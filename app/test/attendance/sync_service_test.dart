import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/features/attendance/application/attendance_sync_service.dart';
import 'package:peopleos/features/attendance/data/outbox_store.dart';
import 'package:peopleos/features/attendance/data/selfie_store.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';
import 'package:peopleos/l10n/generated/app_localizations_en.dart';

import '../helpers/fakes.dart';

void main() {
  late FakeAttendanceRepository repo;
  late MemoryOutboxStore store;
  late MemorySelfieStore selfies;
  late FakeConnectivity net;
  late DateTime now;
  late int keySeq;
  late AttendanceSyncService service;

  CheckCapture capture({
    CheckType type = CheckType.checkIn,
    bool withSelfie = false,
  }) => CheckCapture(
    employeeId: 6,
    type: type,
    fix: FakeLocationService.atOffice(),
    selfie: withSelfie ? tinyPng : null,
    utcOffsetMinutes: 420,
    siteName: 'Head Office',
    clientDistanceMeters: 33,
  );

  setUp(() {
    repo = FakeAttendanceRepository();
    store = MemoryOutboxStore();
    selfies = MemorySelfieStore();
    net = FakeConnectivity();
    now = DateTime.utc(2026, 9, 30, 1, 0);
    keySeq = 0;
    service = AttendanceSyncService(
      repository: repo,
      store: store,
      selfies: selfies,
      connectivity: net,
      clock: () => now,
      newKey: () => 'key-${++keySeq}',
    );
  });

  test(
    'online success: LOCAL -> PENDING_SYNC -> SYNCED with one request',
    () async {
      final out = await service.perform(capture());

      expect(out.kind, SubmitKind.synced);
      expect(out.item.syncState, SyncState.synced);
      expect(repo.submitted, hasLength(1));
      expect(repo.submitted.single.idempotencyKey, 'key-1');
      // First live attempt is not flagged as an offline replay.
      expect(repo.submitted.single.isOfflineSync, isFalse);
      expect((await store.all()).single.syncState, SyncState.synced);
    },
  );

  test(
    'offline: saved as PENDING_SYNC without any request, then synced later',
    () async {
      net.set(false);
      final out = await service.perform(capture());

      expect(out.kind, SubmitKind.savedOffline);
      expect(out.item.syncState, SyncState.pendingSync);
      expect(repo.submitted, isEmpty);
      expect(out.item.isOfflineSync, isTrue);

      net.set(true);
      now = now.add(const Duration(minutes: 5));
      final report = await service.sync(6);

      expect(report.synced, 1);
      expect(report.remaining, 0);
      expect(repo.submitted.single.idempotencyKey, 'key-1');
      expect(
        repo.submitted.single.isOfflineSync,
        isTrue,
        reason: 'replays must be flagged is_offline_sync',
      );
      expect((await store.all()).single.syncState, SyncState.synced);
    },
  );

  test('retry after timeout reuses the same idempotency key', () async {
    var calls = 0;
    repo.submitHandler = (item) {
      calls++;
      if (calls == 1) throw const RequestTimeoutException();
      return FakeAttendanceRepository.okResult(item.type);
    };

    final first = await service.perform(capture());
    expect(first.kind, SubmitKind.savedOffline);
    expect(first.item.syncState, SyncState.pendingSync);
    expect(first.item.attempts, 1);

    now = now.add(const Duration(minutes: 1));
    await service.sync(6);

    expect(repo.submitted.map((e) => e.idempotencyKey), ['key-1', 'key-1']);
    expect(keySeq, 1, reason: 'no second key may be generated');
    expect(await store.all(), hasLength(1));
    expect((await store.all()).single.syncState, SyncState.synced);
  });

  test('server "replayed: true" counts as success', () async {
    repo.submitHandler = (item) =>
        FakeAttendanceRepository.okResult(item.type, replayed: true);

    final out = await service.perform(capture());

    expect(out.kind, SubmitKind.synced);
    expect(out.result!.replayed, isTrue);
  });

  test('duplicate tap does not create two queue items or requests', () async {
    final gate = Completer<void>();
    repo.submitHandler = (item) async {
      await gate.future;
      return FakeAttendanceRepository.okResult(item.type);
    };

    final a = service.perform(capture());
    final b = service.perform(capture());
    await Future<void>.delayed(Duration.zero);
    gate.complete();
    final results = await Future.wait([a, b]);

    expect(results[0].item.idempotencyKey, results[1].item.idempotencyKey);
    expect(repo.submitted, hasLength(1));
    expect(await store.all(), hasLength(1));
  });

  test('a pending item of the same type blocks a second one', () async {
    net.set(false);
    await service.perform(capture());
    final second = await service.perform(capture());

    expect(second.kind, SubmitKind.savedOffline);
    expect(await store.all(), hasLength(1));
  });

  test(
    'business rejection while the user is present is surfaced, not queued',
    () async {
      repo.submitHandler = (item) => throw const ValidationException(
        'Outside geofence',
        code: 'OUTSIDE_GEOFENCE',
        details: {'distance_meters': 320.0, 'radius_meters': 150},
      );

      await expectLater(
        service.perform(capture()),
        throwsA(
          isA<ValidationException>().having(
            (e) => e.code,
            'code',
            'OUTSIDE_GEOFENCE',
          ),
        ),
      );
      expect(await store.all(), isEmpty);
      expect(selfies.count, 0);
    },
  );

  test('business rejection during sync keeps the item as SYNC_FAILED with a readable reason', () async {
    net.set(false);
    await service.perform(capture());
    net.set(true);
    now = now.add(const Duration(minutes: 1));
    repo.submitHandler = (item) => throw const ConflictException(
      'Already checked in',
      code: 'ALREADY_CHECKED_IN',
    );

    final report = await service.sync(6);

    expect(report.failed, 1);
    final item = (await store.all()).single;
    expect(item.syncState, SyncState.syncFailed);
    expect(item.lastErrorCode, 'ALREADY_CHECKED_IN');
    expect(item.errorText(AppLocalizationsEn()), 'You are already checked in.');

    // The user can retry: same key, back to the queue, then delivered.
    repo.submitHandler = null;
    await service.retry(item.idempotencyKey);
    await service.sync(6, force: true);
    expect(repo.submitted.last.idempotencyKey, item.idempotencyKey);
    expect((await store.all()).single.syncState, SyncState.synced);
  });

  test('discard removes the item and its selfie', () async {
    net.set(false);
    final out = await service.perform(capture(withSelfie: true));
    expect(selfies.count, 1);

    await service.discard(out.item.idempotencyKey);

    expect(await store.all(), isEmpty);
    expect(selfies.count, 0);
  });

  test('queued selfie is sent on sync and deleted afterwards', () async {
    net.set(false);
    await service.perform(capture(withSelfie: true));
    net.set(true);
    now = now.add(const Duration(minutes: 1));

    await service.sync(6);

    expect(repo.selfies.single, isNotNull);
    expect(selfies.count, 0);
  });

  test(
    'items are delivered oldest first and a transient failure stops the run',
    () async {
      net.set(false);
      await service.perform(capture());
      now = now.add(const Duration(hours: 8));
      await service.perform(capture(type: CheckType.checkOut));
      net.set(true);

      var first = true;
      repo.submitHandler = (item) {
        if (first) {
          first = false;
          throw const NoConnectionException();
        }
        return FakeAttendanceRepository.okResult(item.type);
      };

      var report = await service.sync(6, force: true);
      expect(report.synced, 0);
      expect(repo.submitted.map((e) => e.type), [
        CheckType.checkIn,
      ], reason: 'check-out must not overtake the failing check-in');

      repo.submitted.clear();
      report = await service.sync(6, force: true);
      expect(report.synced, 2);
      expect(repo.submitted.map((e) => e.type), [
        CheckType.checkIn,
        CheckType.checkOut,
      ]);
    },
  );

  test('back-off is respected unless the user forces a sync', () async {
    repo.submitHandler = (item) => throw const ServerException();
    await service.perform(capture());
    expect(repo.submitted, hasLength(1));

    var report = await service.sync(6);
    expect(
      repo.submitted,
      hasLength(1),
      reason: 'still inside the back-off window',
    );
    expect(report.remaining, 1);

    report = await service.sync(6, force: true);
    expect(repo.submitted, hasLength(2));

    now = now.add(const Duration(minutes: 30));
    await service.sync(6);
    expect(repo.submitted, hasLength(3));
    expect(repo.submitted.map((e) => e.idempotencyKey).toSet(), {'key-1'});
  });

  test('429 uses the server retry-after as next attempt time', () async {
    repo.submitHandler = (item) => throw const RateLimitedException(
      'Slow down',
      retryAfter: Duration(seconds: 90),
    );
    final out = await service.perform(capture());

    expect(out.kind, SubmitKind.savedOffline);
    expect(out.item.nextAttemptAt, now.add(const Duration(seconds: 90)));
  });

  test(
    '401 keeps the action pending so it syncs after the next sign-in',
    () async {
      repo.submitHandler = (item) =>
          throw const UnauthorizedException('expired', code: 'UNAUTHENTICATED');
      final out = await service.perform(capture());

      expect(out.kind, SubmitKind.savedOffline);
      expect(out.item.syncState, SyncState.pendingSync);
      expect(out.item.nextAttemptAt, isNull);
    },
  );

  test('items are scoped to the employee', () async {
    net.set(false);
    await service.perform(capture());

    expect(await service.items(999), isEmpty);
    final report = await service.sync(999, force: true);
    expect(report.synced, 0);
    expect(repo.submitted, isEmpty);
  });

  test('OutboxItem survives a JSON round trip', () async {
    net.set(false);
    final out = await service.perform(capture(withSelfie: true));
    final copy = OutboxItem.fromJson(out.item.toJson());

    expect(copy.idempotencyKey, out.item.idempotencyKey);
    expect(copy.syncState, SyncState.pendingSync);
    expect(copy.latitude, out.item.latitude);
    expect(copy.selfieRef, out.item.selfieRef);
    expect(copy.deviceCapturedAt, out.item.deviceCapturedAt);
  });
}
