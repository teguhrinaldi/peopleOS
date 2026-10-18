import 'package:flutter/foundation.dart';
import 'package:peopleos/core/utils/zoned_time.dart';
import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';

/// What Home shows: the server read model with any not-yet-synced local
/// actions applied on top, clearly flagged via [hasPendingSync].
@immutable
class EffectiveToday {
  const EffectiveToday({
    required this.server,
    required this.state,
    required this.canCheckIn,
    required this.canCheckOut,
    required this.hasPendingSync,
    required this.failedCount,
    required this.fetchedAtUtc,
    this.checkInAt,
    this.checkOutAt,
    this.pendingItems = const [],
  });

  final TodayAttendance server;
  final TodayState state;
  final bool canCheckIn;
  final bool canCheckOut;
  final bool hasPendingSync;
  final int failedCount;
  final DateTime fetchedAtUtc;
  final ZonedTime? checkInAt;
  final ZonedTime? checkOutAt;
  final List<OutboxItem> pendingItems;

  Schedule? get schedule => server.schedule;

  /// Live elapsed working time. Uses the server clock offset measured at
  /// fetch time so a wrong device clock does not skew the timer.
  Duration? elapsedAt(DateTime nowUtc) {
    final start = checkInAt;
    if (start == null) return null;
    final skew = server.serverTime.utc.difference(fetchedAtUtc);
    final end = checkOutAt?.utc ?? nowUtc.add(skew);
    final d = end.difference(start.utc);
    return d.isNegative ? Duration.zero : d;
  }

  /// Late by the server's rule when known, otherwise from the schedule.
  int get lateMinutes {
    final rec = server.attendance;
    if (rec != null && rec.checkInAt == checkInAt) return rec.lateMinutes;
    final s = schedule;
    final at = checkInAt;
    if (s == null || at == null || s.startTime == null) return 0;
    final start = _minutesOf(s.startTime!);
    final actual = at.wall.hour * 60 + at.wall.minute;
    final end = s.endTime == null ? null : _minutesOf(s.endTime!);
    // Outside the shift window there is no meaningful lateness.
    if (end != null && actual >= end) return 0;
    return actual > start + s.graceLateMinutes ? actual - start : 0;
  }

  bool get isLate => lateMinutes > 0;

  /// True when [lateMinutes] was worked out on this device from the schedule
  /// (the check-in is still waiting to sync) rather than confirmed by the
  /// server. The UI labels such lateness as an estimate.
  bool get lateIsEstimate {
    if (!hasPendingSync || checkInAt == null) return false;
    final rec = server.attendance;
    return !(rec != null && rec.checkInAt == checkInAt);
  }

  /// Geofence "review" mode: the shift is running but HR must confirm it.
  bool get isOpenForReview =>
      state == TodayState.pendingReviewOpen && checkInAt != null;
}

EffectiveToday mergeToday({
  required TodayAttendance server,
  required List<OutboxItem> items,
  required int employeeId,
  required DateTime fetchedAtUtc,
}) {
  final active =
      items.where((e) => e.employeeId == employeeId && e.isActive).toList()
        ..sort((a, b) => a.deviceCapturedAt.compareTo(b.deviceCapturedAt));
  final failed = items
      .where(
        (e) =>
            e.employeeId == employeeId && e.syncState == SyncState.syncFailed,
      )
      .length;

  var state = server.state;
  var canIn = server.canCheckIn;
  var canOut = server.canCheckOut;
  var inAt = server.attendance?.checkInAt;
  var outAt = server.attendance?.checkOutAt;
  var applied = false;

  for (final item in active) {
    final at = ZonedTime(
      item.deviceCapturedAt,
      Duration(minutes: item.utcOffsetMinutes),
    );
    if (item.type == CheckType.checkIn && state == TodayState.notCheckedIn) {
      state = TodayState.checkedIn;
      canIn = false;
      canOut = true;
      inAt = at;
      applied = true;
    } else if (item.type == CheckType.checkOut &&
        (state == TodayState.checkedIn ||
            state == TodayState.pendingReviewOpen)) {
      state = TodayState.checkedOut;
      canOut = false;
      outAt = at;
      applied = true;
    }
  }

  return EffectiveToday(
    server: server,
    state: state,
    canCheckIn: canIn,
    canCheckOut: canOut,
    hasPendingSync: applied || active.isNotEmpty,
    failedCount: failed,
    fetchedAtUtc: fetchedAtUtc,
    checkInAt: inAt,
    checkOutAt: outAt,
    pendingItems: active,
  );
}

/// "08:30" -> 510.
int _minutesOf(String hhmm) {
  final p = hhmm.split(':');
  return int.parse(p[0]) * 60 + int.parse(p[1]);
}
