import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';

import '../helpers/fakes.dart';
import '../helpers/harness.dart';
import '../helpers/sample_json.dart';

OutboxItem pendingItem(
  CheckType type, {
  SyncState state = SyncState.pendingSync,
}) => OutboxItem(
  idempotencyKey: 'pending-$type',
  employeeId: 6,
  type: type,
  deviceCapturedAt: DateTime.utc(2026, 9, 30, 1, 30),
  createdAt: DateTime.utc(2026, 9, 30, 1, 30),
  utcOffsetMinutes: 420,
  syncState: state,
  nextAttemptAt: DateTime.now().add(const Duration(hours: 1)),
  lastError: 'You are already checked in.',
  siteName: 'Head Office',
);

void main() {
  testWidgets('header: greeting, name, department, today shift card', (
    tester,
  ) async {
    await pumpApp(tester, TestEnv());

    expect(find.text('Michael Carter'), findsOneWidget);
    expect(find.text('Marketing Department'), findsOneWidget);
    expect(find.text('Good afternoon'), findsOneWidget); // server time 14:35
    expect(find.text("Today's Shift"), findsOneWidget);
    expect(find.text('08:00 - 17:00'), findsOneWidget);
    expect(find.text('Regular Shift · Head Office'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('NOT_CHECKED_IN: shows Check In', (tester) async {
    await pumpApp(tester, TestEnv());

    expect(find.text('Not checked in'), findsOneWidget);
    expect(find.text('Shift starts 08:00 · Head Office'), findsOneWidget);
    expect(find.text('Check In'), findsOneWidget);
    expect(find.text('Check Out'), findsNothing);
    await unmount(tester);
  });

  testWidgets('CHECKED_IN: live timer, WORKING, location and Check Out', (
    tester,
  ) async {
    final env = TestEnv(
      attendance: FakeAttendanceRepository(
        today: todayJson(state: 'CHECKED_IN'),
      ),
    );
    await pumpApp(tester, env);

    expect(find.text("You're Checked In"), findsWidgets);
    expect(find.text('WORKING'), findsOneWidget);
    expect(find.text('Check Out'), findsOneWidget);
    expect(find.text('Since 08:04'), findsOneWidget);
    expect(find.textContaining('Head Office · 16 m'), findsOneWidget);
    expect(find.text('On time'), findsOneWidget);

    // Ticking itself is covered in hero_timer_test.dart with an injected clock.
    expect(
      find.textContaining(RegExp(r'^\d\d:\d\d:\d\d$')),
      findsOneWidget,
      reason: 'live hh:mm:ss timer',
    );
    await unmount(tester);
  });

  testWidgets('CHECKED_IN + late shows the late badge', (tester) async {
    final env = TestEnv(
      attendance: FakeAttendanceRepository(
        today: todayJson(state: 'CHECKED_IN', late: true),
      ),
    );
    await pumpApp(tester, env);

    expect(find.text('Late 22m'), findsOneWidget);
    expect(find.textContaining('estimate'), findsNothing);
    expect(find.text('On time'), findsNothing);
    await unmount(tester);
  });

  testWidgets('CHECKED_OUT: summary and no action button', (tester) async {
    final env = TestEnv(
      attendance: FakeAttendanceRepository(
        today: todayJson(state: 'CHECKED_OUT'),
      ),
    );
    await pumpApp(tester, env);

    expect(find.text('Checked out'), findsOneWidget);
    expect(find.textContaining('08:04 - 17:10'), findsOneWidget);
    expect(find.text('Check In'), findsNothing);
    expect(find.text('Check Out'), findsNothing);
    await unmount(tester);
  });

  testWidgets('NO_SCHEDULE: explains and offers no check-in', (tester) async {
    final env = TestEnv(
      attendance: FakeAttendanceRepository(
        today: todayJson(
          state: 'NO_SCHEDULE',
          blockedReason: 'NO_SCHEDULE',
          schedule: false,
        ),
      ),
    );
    await pumpApp(tester, env);

    expect(find.text('No schedule for today'), findsOneWidget);
    expect(find.text('No shift assigned'), findsOneWidget);
    expect(find.text('Check In'), findsNothing);
    await unmount(tester);
  });

  testWidgets('NOT_WORKING_DAY: day off', (tester) async {
    final env = TestEnv(
      attendance: FakeAttendanceRepository(
        today: todayJson(
          state: 'NOT_WORKING_DAY',
          blockedReason: 'NOT_A_WORKING_DAY',
          working: false,
        ),
      ),
    );
    await pumpApp(tester, env);

    expect(find.text('Not a working day'), findsOneWidget);
    expect(find.text('Rest day'), findsOneWidget);
    expect(find.text('Check In'), findsNothing);
    await unmount(tester);
  });

  testWidgets('BLOCKED (terminated): attendance unavailable', (tester) async {
    final env = TestEnv(
      attendance: FakeAttendanceRepository(
        today: todayJson(
          state: 'BLOCKED',
          blockedReason: 'EMPLOYEE_TERMINATED',
          schedule: false,
        ),
      ),
    );
    await pumpApp(tester, env);

    expect(find.text('Attendance unavailable'), findsOneWidget);
    expect(find.text('Terminated'), findsOneWidget);
    expect(find.textContaining('employment has ended'), findsOneWidget);
    expect(find.text('Check In'), findsNothing);
    await unmount(tester);
  });

  testWidgets(
    'PENDING_REVIEW_OPEN: shift is running, check-out stays available',
    (tester) async {
      final env = TestEnv(
        attendance: FakeAttendanceRepository(
          today: todayJson(state: 'PENDING_REVIEW_OPEN'),
        ),
      );
      await pumpApp(tester, env);

      expect(find.text('Pending'), findsWidgets);
      expect(find.text('Check Out'), findsOneWidget);
      expect(find.text('Since 08:04'), findsOneWidget);
      await unmount(tester);
    },
  );

  testWidgets('CORRECTION_PENDING and PENDING_REVIEW point to requests', (
    tester,
  ) async {
    for (final (state, title) in [
      ('CORRECTION_PENDING', 'Correction pending'),
      ('PENDING_REVIEW', 'Waiting for review'),
    ]) {
      final env = TestEnv(
        attendance: FakeAttendanceRepository(today: todayJson(state: state)),
      );
      await pumpApp(tester, env);
      expect(find.text(title), findsOneWidget, reason: state);
      expect(find.text('View requests'), findsOneWidget, reason: state);
      await unmount(tester);
    }
  });

  testWidgets('pending sync: local check-in is applied and clearly labelled', (
    tester,
  ) async {
    final env = TestEnv();
    await env.outbox.upsert(pendingItem(CheckType.checkIn));
    await pumpApp(tester, env);

    expect(find.text("You're Checked In"), findsWidgets);
    expect(find.text('Since 08:30'), findsOneWidget);
    expect(find.text('Saved on this device. Waiting to sync.'), findsOneWidget);
    expect(find.text('1 pending sync'), findsWidgets);
    expect(find.text('Check Out'), findsOneWidget);
    expect(
      find.text('Pending sync'),
      findsOneWidget,
      reason: 'recent activity row',
    );
    await unmount(tester);
  });

  testWidgets('pending late check-in shows lateness as an on-device estimate', (
    tester,
  ) async {
    final env = TestEnv();
    // 01:22 UTC = 08:22 local (+07:00); shift 08:00 with 10 min grace.
    await env.outbox.upsert(
      OutboxItem(
        idempotencyKey: 'pending-late',
        employeeId: 6,
        type: CheckType.checkIn,
        deviceCapturedAt: DateTime.utc(2026, 9, 30, 1, 22),
        createdAt: DateTime.utc(2026, 9, 30, 1, 22),
        utcOffsetMinutes: 420,
        syncState: SyncState.pendingSync,
        nextAttemptAt: DateTime.now().add(const Duration(hours: 1)),
        siteName: 'Head Office',
      ),
    );
    await pumpApp(tester, env);

    expect(find.text('Since 08:22'), findsOneWidget);
    expect(find.text('Late 22m (estimate)'), findsOneWidget);
    expect(find.text('Late 22m'), findsNothing);
    await unmount(tester);
  });

  testWidgets('failed sync is called out with a way to review it', (
    tester,
  ) async {
    final env = TestEnv();
    await env.outbox.upsert(
      pendingItem(CheckType.checkIn, state: SyncState.syncFailed),
    );
    await pumpApp(tester, env);

    expect(find.textContaining('could not sync'), findsOneWidget);
    expect(find.text('1 sync failed'), findsOneWidget);
    expect(
      find.text('Check In'),
      findsOneWidget,
      reason: 'failed action is not applied',
    );
    await unmount(tester);
  });

  testWidgets('offline banner appears when the connection drops', (
    tester,
  ) async {
    final env = TestEnv();
    await pumpApp(tester, env);
    expect(find.textContaining("You're offline"), findsNothing);

    env.connectivity.set(false);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));

    expect(find.textContaining("You're offline."), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('error state with retry when the first load fails', (
    tester,
  ) async {
    final repo = FakeAttendanceRepository()
      ..todayError = const ServerException();
    final env = TestEnv(attendance: repo);
    await pumpApp(tester, env);

    expect(find.text('Server problem'), findsOneWidget);
    expect(find.text('Try again'), findsOneWidget);

    repo.todayError = null;
    await tester.tap(find.text('Try again'));
    await settle(tester);

    expect(find.text('Michael Carter'), findsOneWidget);
    expect(find.text('Check In'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('offline error copy and timeout copy', (tester) async {
    final offline = FakeAttendanceRepository()
      ..todayError = const NoConnectionException();
    await pumpApp(tester, TestEnv(attendance: offline));
    expect(find.text("You're offline"), findsOneWidget);
    await unmount(tester);

    final slow = FakeAttendanceRepository()
      ..todayError = const RequestTimeoutException();
    await pumpApp(tester, TestEnv(attendance: slow));
    expect(find.text('The server is slow to respond'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('quick actions and bell are reachable', (tester) async {
    await pumpApp(tester, TestEnv());

    for (final label in [
      'Leave',
      'Payslip',
      'Attendance',
      'Requests',
      'Documents',
      'More',
    ]) {
      expect(find.text(label), findsWidgets, reason: label);
    }
    expect(find.byTooltip('Notifications'), findsOneWidget);

    await tester.tap(find.text('Documents'));
    await settle(tester);
    expect(find.text('No documents'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('text scale 1.3 does not overflow on a small phone', (
    tester,
  ) async {
    tester.platformDispatcher.textScaleFactorTestValue = 1.3;
    addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
    final env = TestEnv(
      attendance: FakeAttendanceRepository(
        today: todayJson(state: 'CHECKED_IN', late: true),
      ),
    );
    await pumpApp(tester, env, size: const Size(360, 640));

    expect(tester.takeException(), isNull);
    expect(find.text('Check Out'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('tablet layout renders two columns', (tester) async {
    await pumpApp(tester, TestEnv(), size: const Size(1000, 800));

    final shift = tester.getTopLeft(find.text("Today's Shift"));
    final actions = tester.getTopLeft(find.text('Leave'));
    expect(
      actions.dx,
      greaterThan(shift.dx + 300),
      reason: 'quick actions sit in the right column',
    );
    expect(find.byType(NavigationRail), findsNothing);
    await unmount(tester);
  });
}
