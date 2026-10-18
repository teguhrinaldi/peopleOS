import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/features/leave/domain/leave_models.dart';

import '../helpers/harness.dart';
import '../helpers/sample_json.dart';

LeaveRequest req(Map<String, dynamic> json) =>
    LeaveRequest.fromJson(JsonReader(json));

Future<void> openLeaveTab(WidgetTester tester) async {
  await tester.tap(find.text('Requests').last);
  await settle(tester, seconds: 1);
}

Future<void> openLeaveForm(WidgetTester tester) async {
  await tester.tap(find.text('Leave').first); // quick action on Home
  await settle(tester, seconds: 1);
}

Future<void> chooseType(WidgetTester tester, String name) async {
  await tester.tap(find.text('Choose a type'));
  await settle(tester, seconds: 1);
  await tester.tap(find.text(name).last);
  await settle(tester, seconds: 1);
}

/// Accepts the date picker's initial date (today) for "From".
Future<void> pickToday(WidgetTester tester) async {
  await tester.tap(find.text('First day'));
  await settle(tester, seconds: 1);
  await tester.tap(find.text('OK'));
  await settle(tester, seconds: 1);
}

void main() {
  testWidgets('Requests opens on Leave with balances, filters and requests', (
    tester,
  ) async {
    final env = TestEnv()
      ..leave.requestList = [
        req(leaveRequestJson()),
        req(
          leaveRequestJson(
            id: 6,
            status: 'APPROVED',
            start: '2026-08-25',
            end: '2026-08-27',
          ),
        ),
      ];
    await pumpApp(tester, env);
    await openLeaveTab(tester);

    // Balance strip: limited and unlimited types.
    expect(find.text('6'), findsOneWidget);
    expect(find.text('of 12 available'), findsOneWidget);
    expect(find.text('3 used · 3 pending'), findsOneWidget);
    expect(find.text('days used this year'), findsOneWidget);

    expect(find.text('Request leave'), findsOneWidget);
    expect(find.text('26 – 28 Oct 2026'), findsOneWidget);
    expect(find.text('25 – 27 Aug 2026'), findsOneWidget);

    await tester.tap(find.text('Approved').first);
    await settle(tester, seconds: 1);
    expect(find.text('26 – 28 Oct 2026'), findsNothing);
    expect(find.text('25 – 27 Aug 2026'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('empty leave list explains what to do', (tester) async {
    await pumpApp(tester, TestEnv());
    await openLeaveTab(tester);
    expect(find.text('No leave requests yet'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('detail: rejected shows the reason and timeline, no cancel', (
    tester,
  ) async {
    final env = TestEnv()
      ..leave.requestList = [
        req(
          leaveRequestJson(
            status: 'REJECTED',
            reviewNote: 'Quarter-end closing that week.',
          ),
        ),
      ];
    await pumpApp(tester, env);
    await openLeaveTab(tester);

    await tester.tap(find.text('26 – 28 Oct 2026'));
    await settle(tester, seconds: 1);

    expect(find.text('Rejection reason'), findsOneWidget);
    expect(find.text('Quarter-end closing that week.'), findsOneWidget);
    expect(find.text('Rejected by Amanda Clark'), findsOneWidget);
    expect(find.text('Submitted for approval'), findsOneWidget);
    expect(find.textContaining('Cancel'), findsNothing);
    await unmount(tester);
  });

  testWidgets('detail: pending can be cancelled after confirmation', (
    tester,
  ) async {
    final env = TestEnv()..leave.requestList = [req(leaveRequestJson())];
    await pumpApp(tester, env);
    await openLeaveTab(tester);
    await tester.tap(find.text('26 – 28 Oct 2026'));
    await settle(tester, seconds: 1);

    expect(find.text('Waiting for your manager'), findsOneWidget);
    await tester.tap(find.text('Cancel request'));
    await settle(tester, seconds: 1);
    await tester.tap(find.text('Cancel leave'));
    await settle(tester, seconds: 1);

    expect(env.leave.cancelled, [7]);
    expect(find.text('Leave cancelled'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('detail: a draft can be submitted', (tester) async {
    final env = TestEnv()
      ..leave.requestList = [req(leaveRequestJson(status: 'DRAFT'))];
    await pumpApp(tester, env);
    await openLeaveTab(tester);
    await tester.tap(find.text('26 – 28 Oct 2026'));
    await settle(tester, seconds: 1);

    expect(find.text('Draft, not submitted yet'), findsOneWidget);
    expect(find.text('Edit draft'), findsOneWidget);
    await tester.tap(find.text('Submit request'));
    await settle(tester, seconds: 1);
    expect(env.leave.submitted, [7]);
    await unmount(tester);
  });

  group('create flow', () {
    testWidgets('type hints, live preview, submit', (tester) async {
      final env = TestEnv();
      await pumpApp(tester, env);
      await openLeaveForm(tester);
      expect(find.text('Request leave'), findsOneWidget);
      expect(find.textContaining('Choose a type and dates'), findsOneWidget);

      await chooseType(tester, 'Annual Leave');
      expect(find.text('12 days per year'), findsOneWidget);
      expect(find.text('Request at least 3 days ahead'), findsOneWidget);

      await pickToday(tester);
      expect(env.leave.previewCalls, greaterThan(0));
      expect(find.text('4 days of leave'), findsOneWidget);
      expect(
        find.textContaining('2 days left after this request'),
        findsOneWidget,
      );
      // Single day: half-day choice appears for this type.
      expect(find.text('Morning'), findsOneWidget);

      await tester.tap(find.text('Submit'));
      await settle(tester, seconds: 1);
      expect(
        env.leave.created.single.$2,
        isTrue,
        reason: 'submitted, not draft',
      );
      expect(env.leave.created.single.$1.typeId, 1);
      expect(find.text('Leave request submitted'), findsOneWidget);
      await unmount(tester);
    });

    testWidgets('save as draft', (tester) async {
      final env = TestEnv();
      await pumpApp(tester, env);
      await openLeaveForm(tester);
      await chooseType(tester, 'Annual Leave');
      await pickToday(tester);
      await tester.tap(find.text('Save as draft'));
      await settle(tester, seconds: 1);
      expect(env.leave.created.single.$2, isFalse);
      expect(find.text('Draft saved'), findsOneWidget);
      await unmount(tester);
    });

    testWidgets('required fields and reason policy are checked locally', (
      tester,
    ) async {
      final env = TestEnv();
      await pumpApp(tester, env);
      await openLeaveForm(tester);
      await tester.tap(find.text('Submit'));
      await tester.pump();
      expect(find.text('Choose a leave type.'), findsOneWidget);
      expect(find.text('Choose the first day.'), findsOneWidget);

      await chooseType(tester, 'Sick Leave');
      await pickToday(tester);
      await tester.tap(find.text('Submit'));
      await tester.pump();
      expect(find.text('A reason is required for Sick Leave.'), findsOneWidget);
      expect(env.leave.created, isEmpty);
      await unmount(tester);
    });

    testWidgets('preview warns when the balance is not enough', (tester) async {
      final env = TestEnv()
        ..leave.previewResult = LeavePreview.fromJson(
          JsonReader(leavePreviewJson(days: '15.0', after: '-9.0')),
        );
      await pumpApp(tester, env);
      await openLeaveForm(tester);
      await chooseType(tester, 'Annual Leave');
      await pickToday(tester);
      expect(
        find.text('Not enough balance: 6 days available, this needs 15 days.'),
        findsOneWidget,
      );
      await unmount(tester);
    });

    testWidgets('preview policy errors are shown inline', (tester) async {
      final env = TestEnv()
        ..leave.previewError = const ValidationException(
          'Annual Leave needs 3 day(s) notice.',
          code: 'LEAVE_NOTICE_TOO_SHORT',
        );
      await pumpApp(tester, env);
      await openLeaveForm(tester);
      await chooseType(tester, 'Annual Leave');
      await pickToday(tester);
      expect(find.textContaining('needs more advance notice'), findsOneWidget);
      await unmount(tester);
    });

    testWidgets(
      'server LEAVE_OVERLAP and LEAVE_INSUFFICIENT_BALANCE become readable banners',
      (tester) async {
        final env = TestEnv()
          ..leave.createError = const ConflictException(
            'overlap',
            code: 'LEAVE_OVERLAP',
          );
        await pumpApp(tester, env);
        await openLeaveForm(tester);
        await chooseType(tester, 'Annual Leave');
        await pickToday(tester);
        await tester.tap(find.text('Submit'));
        await settle(tester, seconds: 1);
        expect(
          find.textContaining('overlap another leave request'),
          findsOneWidget,
        );

        env.leave.createError = const ValidationException(
          'Not enough',
          code: 'LEAVE_INSUFFICIENT_BALANCE',
          details: {'available': '2.0', 'requested': '4.0'},
        );
        await tester.tap(find.text('Submit'));
        await settle(tester, seconds: 1);
        expect(
          find.text(
            'Not enough balance: you asked for 4 days but only 2 days are available.',
          ),
          findsOneWidget,
        );
        await unmount(tester);
      },
    );
  });
}
