import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/features/attendance/application/attendance_sync_service.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';
import 'package:peopleos/features/attendance/presentation/attendance_providers.dart';
import 'package:peopleos/features/auth/presentation/auth_controller.dart';

@immutable
class OutboxState {
  const OutboxState({this.items = const [], this.syncing = false});

  final List<OutboxItem> items;
  final bool syncing;

  List<OutboxItem> get active => items.where((e) => e.isActive).toList();
  List<OutboxItem> get failed =>
      items.where((e) => e.syncState == SyncState.syncFailed).toList();
  int get pendingCount => active.length;
  bool get hasWork => active.isNotEmpty || failed.isNotEmpty;

  OutboxState copyWith({List<OutboxItem>? items, bool? syncing}) =>
      OutboxState(items: items ?? this.items, syncing: syncing ?? this.syncing);
}

/// UI-facing wrapper around [AttendanceSyncService]: keeps the visible queue
/// up to date and triggers syncs when the network returns, on a timer (which
/// respects per-item back-off) and on demand ("Sync now").
class OutboxController extends Notifier<OutboxState> {
  Timer? _timer;

  AttendanceSyncService get _service => ref.read(attendanceSyncServiceProvider);

  @override
  OutboxState build() {
    final employeeId = ref.watch(employeeIdProvider);
    ref.listen<bool>(isOnlineProvider, (previous, online) {
      if (online && previous == false) unawaited(syncNow());
    });
    _timer = Timer.periodic(const Duration(seconds: 15), (_) {
      if (state.active.isNotEmpty) unawaited(syncNow(force: false));
    });
    ref.onDispose(() => _timer?.cancel());
    if (employeeId != null) {
      unawaited(refresh().then((_) => syncNow(force: false)));
    }
    return const OutboxState();
  }

  Future<void> refresh() async {
    final id = ref.read(employeeIdProvider);
    if (id == null) return;
    final items = await _service.items(id);
    state = state.copyWith(items: items);
  }

  /// Records and sends one check-in/out. Business rejections are rethrown.
  Future<SubmitOutcome> perform(CheckCapture capture) async {
    try {
      final outcome = await _service.perform(capture);
      await refresh();
      if (outcome.kind == SubmitKind.synced) _invalidateServerData();
      return outcome;
    } finally {
      await refresh();
    }
  }

  Future<void> syncNow({bool force = true}) async {
    final id = ref.read(employeeIdProvider);
    if (id == null || state.syncing) return;
    state = state.copyWith(syncing: true);
    try {
      final report = await _service.sync(id, force: force);
      if (report.synced > 0 || report.failed > 0) _invalidateServerData();
    } finally {
      await refresh();
      state = state.copyWith(syncing: false);
    }
  }

  Future<void> retry(String key) async {
    await _service.retry(key);
    await syncNow();
  }

  Future<void> discard(String key) async {
    await _service.discard(key);
    await refresh();
  }

  void _invalidateServerData() {
    ref.invalidate(serverTodayProvider);
    ref.invalidate(attendanceHistoryProvider);
  }
}

final outboxControllerProvider =
    NotifierProvider<OutboxController, OutboxState>(OutboxController.new);
