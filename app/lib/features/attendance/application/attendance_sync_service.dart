import 'dart:async';
import 'dart:math' as math;
import 'dart:typed_data';

import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/network/connectivity_service.dart';
import 'package:peopleos/core/platform/location_service.dart';
import 'package:peopleos/features/attendance/data/outbox_store.dart';
import 'package:peopleos/features/attendance/data/selfie_store.dart';
import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/features/attendance/domain/attendance_repository.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';
import 'package:uuid/uuid.dart';

/// What the user just did, captured on the device.
class CheckCapture {
  const CheckCapture({
    required this.employeeId,
    required this.type,
    required this.utcOffsetMinutes,
    this.fix,
    this.selfie,
    this.siteName,
    this.clientDistanceMeters,
  });

  final int employeeId;
  final CheckType type;
  final LocationFix? fix;
  final Uint8List? selfie;
  final int utcOffsetMinutes;
  final String? siteName;
  final double? clientDistanceMeters;
}

enum SubmitKind {
  /// The server accepted (or replayed) the action.
  synced,

  /// Stored locally; will be delivered when the network / server recovers.
  savedOffline,
}

class SubmitOutcome {
  const SubmitOutcome(this.kind, this.item, {this.result});
  final SubmitKind kind;
  final OutboxItem item;
  final CheckResult? result;
}

class SyncReport {
  const SyncReport({this.synced = 0, this.failed = 0, this.remaining = 0});
  final int synced;
  final int failed;
  final int remaining;
}

/// Owns the attendance outbox.
///
/// Guarantees:
/// * the action is persisted BEFORE any network call (never silently lost)
/// * one UUID idempotency key per user action, re-sent on every retry
/// * transient failures (offline, timeout, 5xx, 429) keep the item pending
///   with exponential back-off; business rejections (4xx) become SYNC_FAILED
///   with a readable reason and stay visible until retried or discarded
/// * items are delivered in chronological order
class AttendanceSyncService {
  AttendanceSyncService({
    required this._repository,
    required this._store,
    required this._selfies,
    required this._connectivity,
    DateTime Function()? clock,
    String Function()? newKey,
    this.baseBackoff = const Duration(seconds: 10),
    this.maxBackoff = const Duration(minutes: 10),
  }) : _clock = clock ?? DateTime.now,
       _newKey = newKey ?? (() => const Uuid().v4());

  final AttendanceRepository _repository;
  final OutboxStore _store;
  final SelfieStore _selfies;
  final ConnectivityService _connectivity;
  final DateTime Function() _clock;
  final String Function() _newKey;
  final Duration baseBackoff;
  final Duration maxBackoff;

  final Map<CheckType, Future<SubmitOutcome>> _running = {};
  bool _syncing = false;

  Future<List<OutboxItem>> items(int employeeId) async {
    final all = await _store.all();
    return all.where((e) => e.employeeId == employeeId).toList()
      ..sort((a, b) => a.deviceCapturedAt.compareTo(b.deviceCapturedAt));
  }

  /// Records and (if possible) delivers one action.
  ///
  /// Returns [SubmitKind.synced] or [SubmitKind.savedOffline]. Throws the
  /// server's [AppException] when it rejects the action while the user is
  /// present (e.g. `OUTSIDE_GEOFENCE`); nothing is queued in that case
  /// because the user sees the reason immediately and can act on it.
  Future<SubmitOutcome> perform(CheckCapture capture) {
    // A double tap must never create a second queue item.
    final running = _running[capture.type];
    if (running != null) return running;
    final future = _perform(capture).whenComplete(() {
      _running.remove(capture.type);
    });
    _running[capture.type] = future;
    return future;
  }

  Future<SubmitOutcome> _perform(CheckCapture c) async {
    final existing = (await items(c.employeeId))
        .where((e) => e.isActive && e.type == c.type);
    if (existing.isNotEmpty) {
      return SubmitOutcome(SubmitKind.savedOffline, existing.first);
    }

    final key = _newKey();
    final now = _clock().toUtc();
    final selfieRef = c.selfie == null
        ? null
        : await _selfies.save(key, c.selfie!);
    var item = OutboxItem(
      idempotencyKey: key,
      employeeId: c.employeeId,
      type: c.type,
      latitude: c.fix?.latitude,
      longitude: c.fix?.longitude,
      accuracyMeters: c.fix?.accuracyMeters,
      locationCapturedAt: c.fix?.capturedAt.toUtc(),
      deviceCapturedAt: now,
      createdAt: now,
      isMockLocation: c.fix?.isMocked ?? false,
      selfieRef: selfieRef,
      utcOffsetMinutes: c.utcOffsetMinutes,
      siteName: c.siteName,
      clientDistanceMeters: c.clientDistanceMeters,
    );
    await _store.upsert(item);

    if (!await _connectivity.isOnline) {
      item = item.copyWith(
        syncState: SyncState.pendingSync,
        isOfflineSync: true,
        lastErrorCode: 'NO_CONNECTION',
        nextAttemptAt: _clock().add(baseBackoff),
      );
      await _store.upsert(item);
      return SubmitOutcome(SubmitKind.savedOffline, item);
    }
    return _attempt(item, live: true);
  }

  Future<SubmitOutcome> _attempt(OutboxItem item, {required bool live}) async {
    var current = item.copyWith(
      syncState: SyncState.pendingSync,
      attempts: item.attempts + 1,
      clearNextAttempt: true,
    );
    await _store.upsert(current);
    try {
      final selfie = current.selfieRef == null
          ? null
          : await _selfies.read(current.selfieRef!);
      final result = await _repository.submit(current, selfie: selfie);
      current = current.copyWith(
        syncState: SyncState.synced,
        clearError: true,
        clearNextAttempt: true,
      );
      await _store.upsert(current);
      await _dropSelfie(current);
      return SubmitOutcome(SubmitKind.synced, current, result: result);
    } on UnauthorizedException catch (e) {
      // Session expired: keep the action, it syncs after the next sign-in.
      current = _pendingAgain(current, e, retryIn: null);
      await _store.upsert(current);
      return SubmitOutcome(SubmitKind.savedOffline, current);
    } on AppException catch (e) {
      if (e.isTransient) {
        final wait = e is RateLimitedException ? e.retryAfter : null;
        current = _pendingAgain(current, e, retryIn: wait);
        await _store.upsert(current);
        return SubmitOutcome(SubmitKind.savedOffline, current);
      }
      if (live) {
        await _store.remove(current.idempotencyKey);
        await _dropSelfie(current);
        rethrow;
      }
      current = current.copyWith(
        syncState: SyncState.syncFailed,
        lastError: e.message,
        lastErrorCode: e.code,
        clearNextAttempt: true,
      );
      await _store.upsert(current);
      return SubmitOutcome(SubmitKind.savedOffline, current);
    }
  }

  OutboxItem _pendingAgain(
    OutboxItem item,
    AppException e, {
    required Duration? retryIn,
  }) {
    return item.copyWith(
      syncState: SyncState.pendingSync,
      isOfflineSync: true,
      lastError: e.message,
      lastErrorCode: e.code,
      nextAttemptAt: retryIn == null && e is UnauthorizedException
          ? null
          : _clock().add(retryIn ?? _backoff(item.attempts)),
    );
  }

  Duration _backoff(int attempts) {
    final factor = 1 << (attempts <= 1 ? 0 : math.min(attempts - 1, 10));
    final d = baseBackoff * factor;
    return d > maxBackoff ? maxBackoff : d;
  }

  /// Delivers waiting items oldest-first. A transient failure stops the run
  /// (later items must not overtake an earlier one). [force] ignores back-off
  /// (manual "Sync now").
  Future<SyncReport> sync(int employeeId, {bool force = false}) async {
    if (_syncing) return const SyncReport();
    _syncing = true;
    var synced = 0;
    var failed = 0;
    try {
      final waiting = (await items(employeeId)).where((e) => e.isActive);
      for (final item in waiting) {
        if (!force &&
            item.nextAttemptAt != null &&
            item.nextAttemptAt!.isAfter(_clock())) {
          break;
        }
        final outcome = await _attempt(
          item.copyWith(isOfflineSync: true),
          live: false,
        );
        if (outcome.kind == SubmitKind.synced) {
          synced++;
        } else if (outcome.item.syncState == SyncState.syncFailed) {
          failed++;
        } else {
          break;
        }
      }
      final remaining = (await items(employeeId))
          .where((e) => e.isActive)
          .length;
      return SyncReport(synced: synced, failed: failed, remaining: remaining);
    } finally {
      _syncing = false;
    }
  }

  /// Puts a SYNC_FAILED item back in the queue (same idempotency key).
  Future<void> retry(String idempotencyKey) async {
    final all = await _store.all();
    final item = all.where((e) => e.idempotencyKey == idempotencyKey);
    if (item.isEmpty) return;
    await _store.upsert(
      item.first.copyWith(
        syncState: SyncState.pendingSync,
        clearError: true,
        clearNextAttempt: true,
      ),
    );
  }

  Future<void> discard(String idempotencyKey) async {
    final all = await _store.all();
    for (final item in all.where((e) => e.idempotencyKey == idempotencyKey)) {
      await _dropSelfie(item);
    }
    await _store.remove(idempotencyKey);
  }

  /// Removes delivered items older than [keep] so the store stays small.
  Future<void> purgeSynced({Duration keep = const Duration(days: 3)}) async {
    final cutoff = _clock().toUtc().subtract(keep);
    for (final item in await _store.all()) {
      if (item.syncState == SyncState.synced &&
          item.createdAt.isBefore(cutoff)) {
        await _store.remove(item.idempotencyKey);
      }
    }
  }

  Future<void> _dropSelfie(OutboxItem item) async {
    if (item.selfieRef != null) await _selfies.delete(item.selfieRef!);
  }
}
