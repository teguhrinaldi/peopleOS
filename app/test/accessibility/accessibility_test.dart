import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/features/leave/domain/leave_models.dart';

import '../helpers/batch2_fakes.dart';
import '../helpers/batch2_json.dart';
import '../helpers/harness.dart';
import '../helpers/sample_json.dart';

double _lum(Color c) {
  double ch(double v) =>
      v <= 0.03928 ? v / 12.92 : math.pow((v + 0.055) / 1.055, 2.4).toDouble();
  return 0.2126 * ch(c.r) + 0.7152 * ch(c.g) + 0.0722 * ch(c.b);
}

double contrast(Color a, Color b) {
  final l1 = _lum(a);
  final l2 = _lum(b);
  final hi = math.max(l1, l2);
  final lo = math.min(l1, l2);
  return (hi + 0.05) / (lo + 0.05);
}

void main() {
  group('WCAG AA (4.5:1) for text on its surface, light and dark', () {
    for (final entry in {
      'light': PeopleColors.light,
      'dark': PeopleColors.dark,
    }.entries) {
      final c = entry.value;
      final pairs = <String, (Color, Color)>{
        'primary text on background': (c.textPrimary, c.background),
        'primary text on surface': (c.textPrimary, c.surface),
        'secondary text on surface': (c.textSecondary, c.surface),
        'muted text on surface': (c.textMuted, c.surface),
        'muted text on background': (c.textMuted, c.background),
        'link on surface': (c.primaryText, c.surface),
        'link on primarySoft': (c.primaryText, c.primarySoft),
        'on primary button': (c.onPrimary, c.primary),
        'on navy button': (c.onNavy, c.navy),
        'success text on soft': (c.successText, c.successSoft),
        'warning text on soft': (c.warningText, c.warningSoft),
        'error text on soft': (c.errorText, c.errorSoft),
        'info text on soft': (c.infoText, c.infoSoft),
        'secondary on neutralSoft': (c.textSecondary, c.neutralSoft),
        'success text on surface': (c.successText, c.surface),
        'error text on surface': (c.errorText, c.surface),
        'warning text on surface': (c.warningText, c.surface),
      };
      pairs.forEach((name, pair) {
        test('${entry.key}: $name', () {
          final ratio = contrast(pair.$1, pair.$2);
          expect(
            ratio,
            greaterThanOrEqualTo(4.5),
            reason: 'contrast ${ratio.toStringAsFixed(2)}',
          );
        });
      });
    }
  });

  test('reduced motion removes animation durations', () {
    // Verified through a widget below; the helper itself is pure.
    expect(PeopleMotion.medium, greaterThan(Duration.zero));
  });

  testWidgets('PeopleMotion.of is zero when the OS disables animations', (
    tester,
  ) async {
    Duration? seen;
    await tester.pumpWidget(
      MediaQuery(
        data: const MediaQueryData(disableAnimations: true),
        child: Builder(
          builder: (context) {
            seen = PeopleMotion.of(context, PeopleMotion.slow);
            return const SizedBox();
          },
        ),
      ),
    );
    expect(seen, Duration.zero);
  });

  group(
    'text scale 1.3 on a small phone: no overflow, actions still reachable',
    () {
      Future<void> boot(WidgetTester tester, TestEnv env) async {
        tester.platformDispatcher.textScaleFactorTestValue = 1.3;
        addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
        await pumpApp(tester, env, size: const Size(360, 640));
      }

      testWidgets('Payslip list + detail', (tester) async {
        final env = TestEnv(
          payslips: FakePayslipRepository(
            items: [FakePayslipRepository.sample()],
          ),
        );
        await boot(tester, env);
        await tester.tap(find.text('Payslip').last);
        await settle(tester, seconds: 1);
        await tester.tap(find.text('August 2026').first);
        await settle(tester, seconds: 1);
        expect(find.text('Net pay'), findsOneWidget);
        expect(tester.takeException(), isNull);
        await unmount(tester);
      });

      testWidgets('Documents list', (tester) async {
        final env = TestEnv(
          documents: FakeDocumentsRepository()
            ..items = [
              FakeDocumentsRepository.sample(documentJson()),
              FakeDocumentsRepository.sample(
                documentJson(
                  id: 22,
                  title: 'A very long certificate title that must wrap',
                  status: 'EXPIRED',
                  days: -50,
                ),
              ),
            ],
        );
        await boot(tester, env);
        await tester.tap(find.text('Profile').last);
        await settle(tester, seconds: 1);
        await tester.tap(find.text('Documents'));
        await settle(tester, seconds: 1);
        expect(find.text('Expired'), findsWidgets);
        expect(tester.takeException(), isNull);
        await unmount(tester);
      });

      testWidgets('Leave tab and create form', (tester) async {
        final env = TestEnv()
          ..leave.requestList = [
            LeaveRequest.fromJson(JsonReader(leaveRequestJson())),
          ];
        await boot(tester, env);
        await tester.tap(find.text('Requests').last);
        await settle(tester, seconds: 1);
        expect(find.text('Request leave'), findsOneWidget);
        await tester.tap(find.text('Request leave'));
        await settle(tester, seconds: 1);
        expect(find.text('Submit'), findsOneWidget);
        expect(tester.takeException(), isNull);
        await unmount(tester);
      });

      testWidgets('Notifications and Profile', (tester) async {
        await boot(tester, TestEnv());
        await tester.tap(find.byTooltip('Notifications'));
        await settle(tester, seconds: 1);
        expect(tester.takeException(), isNull);
        await tester.pageBack();
        await settle(tester, seconds: 1);
        await tester.tap(find.text('Profile').last);
        await settle(tester, seconds: 1);
        expect(find.text('Logout'), findsOneWidget);
        expect(tester.takeException(), isNull);
        await unmount(tester);
      });
    },
  );

  testWidgets(
    'interactive targets are at least 48dp tall (home, requests, profile)',
    (tester) async {
      final env = TestEnv();
      await pumpApp(tester, env);
      final targets = <String>['Check In', 'Leave', 'Payslip', 'Documents'];
      for (final label in targets) {
        final box = tester.getSize(find.text(label).first);
        // The label sits inside a larger tappable card/button: walk up to it.
        final inkWell = find
            .ancestor(
              of: find.text(label).first,
              matching: find.byType(InkWell),
            )
            .first;
        final size = tester.getSize(inkWell);
        expect(
          size.height,
          greaterThanOrEqualTo(kMinTap - 0.5),
          reason: '$label (${box.height})',
        );
      }
      await unmount(tester);
    },
  );

  testWidgets('status is never colour-only: badges carry text', (tester) async {
    final env = TestEnv(
      documents: FakeDocumentsRepository()
        ..items = [
          FakeDocumentsRepository.sample(
            documentJson(status: 'EXPIRED', days: -3),
          ),
        ],
    );
    await pumpApp(tester, env);
    await tester.tap(find.text('Profile').last);
    await settle(tester, seconds: 1);
    await tester.tap(find.text('Documents'));
    await settle(tester, seconds: 1);
    expect(find.text('Expired'), findsWidgets);
    expect(find.text('Expired 3 days ago'), findsOneWidget);
    expect(find.byIcon(Icons.error_outline_rounded), findsWidgets);
    await unmount(tester);
  });
}
