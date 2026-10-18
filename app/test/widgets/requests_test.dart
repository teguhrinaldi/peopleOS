import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/app/router.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/features/requests/domain/request_models.dart';

import '../helpers/fakes.dart';
import '../helpers/harness.dart';
import '../helpers/sample_json.dart';

void main() {
  testWidgets('lists corrections with status badges', (tester) async {
    final requests = FakeRequestsRepository()
      ..correctionList = [
        CorrectionRequest.fromJson(JsonReader(correctionJson())),
        CorrectionRequest.fromJson(
          JsonReader(correctionJson(status: 'REJECTED')),
        ),
      ];
    await pumpApp(tester, TestEnv(requests: requests));

    await tester.tap(find.text('Requests').last);
    await settle(tester, seconds: 1);
    await tester.tap(find.text('Corrections'));
    await settle(tester, seconds: 1);

    expect(find.text('Corrections'), findsOneWidget);
    expect(find.text('Pending'), findsOneWidget);
    expect(find.text('Rejected'), findsOneWidget);
    expect(find.text('Request correction'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('segments: overtime list and back to leave', (tester) async {
    final requests = FakeRequestsRepository()
      ..overtimeList = [OvertimeRequest.fromJson(JsonReader(overtimeJson()))];
    await pumpApp(tester, TestEnv(requests: requests));
    await tester.tap(find.text('Requests').last);
    await settle(tester, seconds: 1);
    await tester.tap(find.text('Corrections'));
    await settle(tester, seconds: 1);

    await tester.tap(find.text('Overtime'));
    await settle(tester, seconds: 1);
    expect(find.text('Release prep'), findsOneWidget);
    expect(find.textContaining('17:00 - 19:00 · 2h'), findsOneWidget);

    await tester.tap(find.text('Leave').first);
    await settle(tester, seconds: 1);
    expect(find.text('Request leave'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('pending request detail offers cancel; approved does not', (
    tester,
  ) async {
    final requests = FakeRequestsRepository()
      ..correctionList = [
        CorrectionRequest.fromJson(JsonReader(correctionJson())),
        CorrectionRequest.fromJson(
          JsonReader({
            ...correctionJson(status: 'APPROVED'),
            'id': 13,
            'reason': 'Other day',
          }),
        ),
      ];
    await pumpApp(tester, TestEnv(requests: requests));
    await tester.tap(find.text('Requests').last);
    await settle(tester, seconds: 1);
    await tester.tap(find.text('Corrections'));
    await settle(tester, seconds: 1);

    await tester.tap(find.text('Forgot to check out'));
    await settle(tester, seconds: 1);
    expect(find.text('Correction request'), findsOneWidget);
    expect(find.text('Cancel request'), findsOneWidget);
    await tester.tapAt(const Offset(10, 10)); // dismiss sheet
    await settle(tester, seconds: 1);

    await tester.tap(find.text('Other day'));
    await settle(tester, seconds: 1);
    expect(find.text('Correction request'), findsOneWidget);
    expect(find.text('Cancel request'), findsNothing);
    await unmount(tester);
  });

  group('correction form', () {
    Future<ProviderContainer> openForm(WidgetTester tester, TestEnv env) async {
      await pumpApp(tester, env);
      final container = ProviderScope.containerOf(
        tester.element(find.byType(MaterialApp)),
      );
      unawaited(
        container
            .read(routerProvider)
            .push(Routes.newCorrection, extra: DateTime(2026, 9, 29)),
      );
      await settle(tester, seconds: 1);
      return container;
    }

    testWidgets('local validation: needs a time and a reason', (tester) async {
      final env = TestEnv();
      await openForm(tester, env);

      await tester.tap(find.text('Submit request'));
      await tester.pump();

      expect(
        find.text('Add a check-in time, a check-out time, or both.'),
        findsOneWidget,
      );
      expect(
        find.text('Explain what happened (at least 5 characters).'),
        findsOneWidget,
      );
      expect(env.requests.createCalls, 0);
      await unmount(tester);
    });

    testWidgets('422 field errors from the server are shown under the fields', (
      tester,
    ) async {
      final env = TestEnv();
      env.requests.createError = const ValidationException(
        'The given data was invalid.',
        code: 'VALIDATION_ERROR',
        fieldErrors: {
          'reason': ['The reason is too vague.'],
          'requested_check_out_at': [
            'The check-out must be after the check-in.',
          ],
        },
      );
      await openForm(tester, env);

      await tester.tap(find.byIcon(Icons.logout_rounded));
      await settle(tester, seconds: 1);
      await tester.tap(find.text('OK'));
      await settle(tester, seconds: 1);
      await tester.enterText(
        find.byType(TextFormField),
        'I forgot to check out',
      );
      await tester.tap(find.text('Submit request'));
      await settle(tester, seconds: 1);

      expect(env.requests.createCalls, 1);
      expect(find.text('The reason is too vague.'), findsOneWidget);
      expect(
        find.text('The check-out must be after the check-in.'),
        findsOneWidget,
      );
      // The company offset is applied to the wall-clock time picked.
      expect(env.requests.lastCheckOut!.toIso(), '2026-09-29T17:00:00+07:00');
      await unmount(tester);
    });

    testWidgets('409 pending-duplicate becomes a readable banner', (
      tester,
    ) async {
      final env = TestEnv();
      env.requests.createError = const ConflictException(
        'dup',
        code: 'CORRECTION_ALREADY_PENDING',
      );
      await openForm(tester, env);

      await tester.tap(find.byIcon(Icons.login_rounded));
      await settle(tester, seconds: 1);
      await tester.tap(find.text('OK'));
      await settle(tester, seconds: 1);
      await tester.enterText(find.byType(TextFormField), 'Forgot to check in');
      await tester.tap(find.text('Submit request'));
      await settle(tester, seconds: 1);

      expect(
        find.text('There is already a pending correction for this date.'),
        findsOneWidget,
      );
      await unmount(tester);
    });

    testWidgets('success closes the form and shows a confirmation', (
      tester,
    ) async {
      final env = TestEnv();
      await openForm(tester, env);

      await tester.tap(find.byIcon(Icons.login_rounded));
      await settle(tester, seconds: 1);
      await tester.tap(find.text('OK'));
      await settle(tester, seconds: 1);
      await tester.enterText(find.byType(TextFormField), 'Forgot to check in');
      await tester.tap(find.text('Submit request'));
      await settle(tester, seconds: 1);

      expect(find.text('Correction request submitted'), findsOneWidget);
      expect(find.text('Submit request'), findsNothing, reason: 'form closed');
      await unmount(tester);
    });
  });
}
