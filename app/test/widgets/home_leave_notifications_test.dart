import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/features/leave/domain/leave_models.dart';

import '../helpers/fakes.dart';
import '../helpers/harness.dart';
import '../helpers/sample_json.dart';

void main() {
  testWidgets('ON_LEAVE: hero says on leave and offers no check-in', (
    tester,
  ) async {
    final env = TestEnv(
      attendance: FakeAttendanceRepository(
        today: todayJson(state: 'ON_LEAVE', blockedReason: 'ON_LEAVE'),
      ),
    );
    await pumpApp(tester, env);

    expect(find.text('On leave today'), findsOneWidget);
    expect(find.text('On leave'), findsWidgets);
    expect(find.text('Check In'), findsNothing);
    expect(find.text('Check Out'), findsNothing);
    await unmount(tester);
  });

  testWidgets('summary card: next approved leave and pending count', (
    tester,
  ) async {
    final now = DateTime.now();
    String d(int days) {
      final x = DateTime(now.year, now.month, now.day + days);
      return '${x.year}-${x.month.toString().padLeft(2, '0')}-${x.day.toString().padLeft(2, '0')}';
    }

    final env = TestEnv()
      ..leave.requestList = [
        LeaveRequest.fromJson(
          JsonReader(
            leaveRequestJson(
              id: 1,
              status: 'APPROVED',
              start: d(10),
              end: d(12),
            ),
          ),
        ),
        LeaveRequest.fromJson(
          JsonReader(leaveRequestJson(id: 2, start: d(30), end: d(31))),
        ),
      ];
    await pumpApp(tester, env);

    expect(find.text('Next leave'), findsOneWidget);
    expect(find.textContaining('Annual Leave · '), findsOneWidget);
    expect(find.text('1 pending'), findsOneWidget);

    await tester.tap(find.text('Next leave'));
    await settle(tester, seconds: 1);
    expect(
      find.text('Request leave'),
      findsOneWidget,
      reason: 'opens Requests > Leave',
    );
    await unmount(tester);
  });

  testWidgets('summary card with nothing planned', (tester) async {
    await pumpApp(tester, TestEnv());
    expect(find.text('No upcoming leave'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('bell shows the unread count from the API', (tester) async {
    await pumpApp(tester, TestEnv());
    expect(find.text('1'), findsWidgets);
    expect(find.byTooltip('Notifications'), findsOneWidget);
    await unmount(tester);
  });

  group('notifications inbox', () {
    testWidgets(
      'lists items, opening one marks it read and goes to the leave',
      (tester) async {
        final env = TestEnv()
          ..leave.requestList = [
            LeaveRequest.fromJson(JsonReader(leaveRequestJson())),
          ];
        await pumpApp(tester, env);
        await tester.tap(find.byTooltip('Notifications'));
        await settle(tester, seconds: 1);

        expect(find.text('Leave approved'), findsOneWidget);
        expect(find.text('Attendance correction approved'), findsOneWidget);
        expect(find.text('Mark all read'), findsOneWidget);

        await tester.tap(find.text('Leave approved'));
        await settle(tester, seconds: 1);

        expect(env.notifications.markedRead, [2]);
        expect(
          find.text('Leave request'),
          findsOneWidget,
          reason: 'leave detail page',
        );
        expect(find.text('26 – 28 Oct 2026'), findsOneWidget);
        await unmount(tester);
      },
    );

    testWidgets('correction notification opens Requests > Corrections', (
      tester,
    ) async {
      final env = TestEnv();
      await pumpApp(tester, env);
      await tester.tap(find.byTooltip('Notifications'));
      await settle(tester, seconds: 1);
      await tester.tap(find.text('Attendance correction approved'));
      await settle(tester, seconds: 1);
      expect(find.text('Request correction'), findsOneWidget);
      await unmount(tester);
    });

    testWidgets('mark all read', (tester) async {
      final env = TestEnv();
      await pumpApp(tester, env);
      await tester.tap(find.byTooltip('Notifications'));
      await settle(tester, seconds: 1);
      await tester.tap(find.text('Mark all read'));
      await settle(tester, seconds: 1);
      expect(env.notifications.markAllCalls, 1);
      expect(find.text('Mark all read'), findsNothing);
      await unmount(tester);
    });

    testWidgets('empty inbox', (tester) async {
      final env = TestEnv()..notifications.items = [];
      await pumpApp(tester, env);
      await tester.tap(find.byTooltip('Notifications'));
      await settle(tester, seconds: 1);
      expect(find.text("You're all caught up"), findsOneWidget);
      await unmount(tester);
    });
  });
}
