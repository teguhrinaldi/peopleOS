import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/features/attendance/domain/effective_today.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';

import '../helpers/sample_json.dart';

TodayAttendance today(String state, {bool late = false}) =>
    TodayAttendance.fromJson(JsonReader(todayJson(state: state, late: late)));

OutboxItem pending(
  CheckType type, {
  SyncState state = SyncState.pendingSync,
  DateTime? at,
  int employee = 6,
}) => OutboxItem(
  idempotencyKey: 'k-$type-${at?.hour ?? 0}',
  employeeId: employee,
  type: type,
  deviceCapturedAt: at ?? DateTime.utc(2026, 9, 30, 1, 5), // 08:05 +07:00
  createdAt: DateTime.utc(2026, 9, 30, 1, 5),
  utcOffsetMinutes: 420,
  syncState: state,
);

EffectiveToday merge(TodayAttendance t, List<OutboxItem> items) => mergeToday(
  server: t,
  items: items,
  employeeId: 6,
  fetchedAtUtc: DateTime.utc(2026, 9, 30, 7, 35, 11),
);

void main() {
  test('nothing pending leaves the server state untouched', () {
    final e = merge(today('NOT_CHECKED_IN'), []);
    expect(e.state, TodayState.notCheckedIn);
    expect(e.canCheckIn, isTrue);
    expect(e.hasPendingSync, isFalse);
  });

  test(
    'a pending check-in shows as checked in (pending sync) with local time',
    () {
      final e = merge(today('NOT_CHECKED_IN'), [pending(CheckType.checkIn)]);
      expect(e.state, TodayState.checkedIn);
      expect(e.canCheckIn, isFalse);
      expect(e.canCheckOut, isTrue);
      expect(e.hasPendingSync, isTrue);
      expect(e.checkInAt!.hhmm, '08:05');
      expect(e.isLate, isFalse);
    },
  );

  test(
    'a pending check-in after the grace period is late by the schedule rule',
    () {
      final e = merge(today('NOT_CHECKED_IN'), [
        pending(
          CheckType.checkIn,
          at: DateTime.utc(2026, 9, 30, 1, 40),
        ), // 08:40
      ]);
      expect(e.isLate, isTrue);
      expect(e.lateMinutes, 40);
    },
  );

  test('pending check-in + check-out => checked out', () {
    final e = merge(today('NOT_CHECKED_IN'), [
      pending(CheckType.checkIn),
      pending(CheckType.checkOut, at: DateTime.utc(2026, 9, 30, 10, 0)),
    ]);
    expect(e.state, TodayState.checkedOut);
    expect(e.canCheckIn, isFalse);
    expect(e.canCheckOut, isFalse);
    expect(e.checkOutAt!.hhmm, '17:00');
  });

  test('a pending check-out on a server-side open day => checked out', () {
    final e = merge(today('CHECKED_IN'), [
      pending(CheckType.checkOut, at: DateTime.utc(2026, 9, 30, 10, 0)),
    ]);
    expect(e.state, TodayState.checkedOut);
    expect(e.checkInAt!.hhmm, '08:04');
    expect(e.hasPendingSync, isTrue);
  });

  test('failed and other employees\' items are not applied but failures are counted', () {
    final e = merge(today('NOT_CHECKED_IN'), [
      pending(CheckType.checkIn, state: SyncState.syncFailed),
      pending(CheckType.checkIn, employee: 99),
    ]);
    expect(e.state, TodayState.notCheckedIn);
    expect(e.hasPendingSync, isFalse);
    expect(e.failedCount, 1);
  });

  test('blocked days stay blocked even with a stale pending item', () {
    final e = merge(today('BLOCKED'), [pending(CheckType.checkIn)]);
    expect(e.state, TodayState.blocked);
  });

  test('elapsed time is anchored to server time, not the device clock', () {
    // Server clock said 07:35:11Z while the device believed it was 06:35:11Z
    // (1h behind). Five minutes later the timer must still be exact.
    final e = mergeToday(
      server: today('CHECKED_IN'),
      items: const [],
      employeeId: 6,
      fetchedAtUtc: DateTime.utc(2026, 9, 30, 6, 35, 11),
    );
    final elapsed = e.elapsedAt(DateTime.utc(2026, 9, 30, 6, 40, 11))!;
    // 08:04+07 = 01:04Z -> 07:40:11Z (server time) is 6h36m11s later.
    expect(elapsed, const Duration(hours: 6, minutes: 36, seconds: 11));
  });

  test('closed day: elapsed is check-out minus check-in', () {
    final e = merge(today('CHECKED_OUT'), []);
    expect(
      e.elapsedAt(DateTime.utc(2030))!,
      const Duration(hours: 9, minutes: 6),
    );
  });

  test('cached read model rolls over to an open day after midnight', () {
    final cached = today('CHECKED_OUT');
    final sameDay = cached.asStaleAt(DateTime.utc(2026, 9, 30, 12));
    expect(sameDay.state, TodayState.checkedOut);
    expect(sameDay.isStale, isTrue);

    final nextDay = cached.asStaleAt(DateTime.utc(2026, 10, 1, 2)); // 09:00 +07
    expect(nextDay.state, TodayState.notCheckedIn);
    expect(nextDay.canCheckIn, isTrue);
    expect(nextDay.attendance, isNull);
    expect(nextDay.isStale, isTrue);
  });
}
