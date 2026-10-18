import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/core/widgets/navigation.dart';
import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/features/leave/domain/leave_models.dart';

import '../helpers/batch2_fakes.dart';
import '../helpers/batch2_json.dart';
import '../helpers/harness.dart';
import '../helpers/sample_json.dart';

const tablet = Size(1100, 800);

void main() {
  testWidgets(
    'persistent side rail with all five destinations, no bottom bar',
    (tester) async {
      await pumpApp(tester, TestEnv(), size: tablet);

      expect(find.byType(AppSideRail), findsOneWidget);
      expect(find.byType(AppBottomNav), findsNothing);
      for (final label in [
        'Home',
        'Attendance',
        'Requests',
        'Payslip',
        'Profile',
      ]) {
        expect(find.text(label), findsWidgets, reason: label);
      }
      await unmount(tester);
    },
  );

  testWidgets('Home: hero + shift left, updates + activity right', (
    tester,
  ) async {
    final env = TestEnv(
      payslips: FakePayslipRepository(items: [FakePayslipRepository.sample()]),
    );
    await pumpApp(tester, env, size: tablet);

    final hero = tester.getTopLeft(find.text("Today's Shift"));
    final updates = tester.getTopLeft(find.text('Updates'));
    final recent = tester.getTopLeft(find.text('Recent activity'));
    expect(updates.dx, greaterThan(hero.dx + 300));
    expect(recent.dx, updates.dx);
    await unmount(tester);
  });

  testWidgets('Payslip: list and the selected payslip side by side', (
    tester,
  ) async {
    final env = TestEnv(
      payslips: FakePayslipRepository(
        items: [
          FakePayslipRepository.sample(),
          FakePayslipRepository.sample(
            id: 6,
            code: '2026-07',
            name: 'July 2026',
          ),
        ],
      ),
    );
    await pumpApp(tester, env, size: tablet);
    await tester.tap(find.text('Payslip').last);
    await settle(tester, seconds: 1);

    // The newest payslip is pre-selected: its detail shows next to the list.
    expect(find.text('Net pay'), findsOneWidget);
    expect(find.text('Download PDF'), findsOneWidget);
    final list = tester.getTopLeft(find.text('July 2026'));
    final detail = tester.getTopLeft(find.text('Net pay'));
    expect(detail.dx, greaterThan(list.dx + 300));

    await tester.tap(find.text('July 2026'));
    await settle(tester, seconds: 1);
    expect(
      find.text('Net pay'),
      findsOneWidget,
      reason: 'pane switched, not stacked',
    );
    await unmount(tester);
  });

  testWidgets('Requests: leave detail opens in the right pane, not a sheet', (
    tester,
  ) async {
    final env = TestEnv()
      ..leave.requestList = [
        LeaveRequest.fromJson(
          JsonReader(
            leaveRequestJson(
              status: 'REJECTED',
              reviewNote: 'Quarter-end closing.',
            ),
          ),
        ),
      ];
    await pumpApp(tester, env, size: tablet);
    await tester.tap(find.text('Requests').last);
    await settle(tester, seconds: 1);

    expect(find.text('Select a request'), findsOneWidget);
    await tester.tap(find.text('26 – 28 Oct 2026'));
    await settle(tester, seconds: 1);

    expect(find.text('Rejection reason'), findsOneWidget);
    expect(find.text('Select a request'), findsNothing);
    expect(find.byType(BottomSheet), findsNothing);
    await unmount(tester);
  });

  testWidgets('Attendance: history list with a detail pane', (tester) async {
    final env = TestEnv();
    env.attendance.records = [
      AttendanceRecord.fromJson(
        JsonReader(attendanceJson(status: 'CHECKED_OUT', checkedOut: true)),
      ),
    ];
    await pumpApp(tester, env, size: tablet);
    await tester.tap(find.text('Attendance').last);
    await settle(tester, seconds: 1);

    expect(find.text('Select a day'), findsOneWidget);
    await tester.tap(find.textContaining('Sep 30').first);
    await settle(tester, seconds: 1);
    expect(find.text('Check-in details'), findsOneWidget);
    expect(find.byType(BottomSheet), findsNothing);
    await unmount(tester);
  });

  testWidgets('Documents: list and preview/detail side by side', (
    tester,
  ) async {
    final env = TestEnv(
      documents: FakeDocumentsRepository()
        ..items = [FakeDocumentsRepository.sample(documentJson())],
    );
    await pumpApp(tester, env, size: tablet);
    await tester.tap(find.text('Profile').last);
    await settle(tester, seconds: 1);
    await tester.tap(find.text('Documents'));
    await settle(tester, seconds: 1);

    // First document is pre-selected on wide screens.
    expect(find.text('Download / open'), findsOneWidget);
    final list = tester.getTopLeft(find.text('Expiring').first);
    final detail = tester.getTopLeft(find.text('Download / open'));
    expect(detail.dx, greaterThan(list.dx + 300));
    await unmount(tester);
  });

  testWidgets('wide content is width-capped, not stretched', (tester) async {
    await pumpApp(tester, TestEnv(), size: const Size(1600, 900));
    await tester.tap(find.text('Profile').last);
    await settle(tester, seconds: 1);
    expect(tester.getSize(find.byType(ListView).last).width, lessThan(700));
    await unmount(tester);
  });
}
