import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/features/notifications/domain/app_notification.dart';
import 'package:peopleos/core/error/app_exception.dart';

import '../helpers/batch2_fakes.dart';
import '../helpers/batch2_json.dart';
import '../helpers/harness.dart';
import '../helpers/sample_json.dart';

TestEnv withPayslips() => TestEnv(
  payslips: FakePayslipRepository(
    items: [
      FakePayslipRepository.sample(),
      FakePayslipRepository.sample(
        id: 6,
        code: '2025-12',
        name: 'December 2025',
      ),
    ],
  ),
);

Future<void> openPayslips(WidgetTester tester) async {
  await tester.tap(find.text('Payslip').last);
  await settle(tester, seconds: 1);
}

void main() {
  group('payslip tab', () {
    testWidgets('lists released payslips with net pay, number and status', (
      tester,
    ) async {
      final env = withPayslips();
      await pumpApp(tester, env);
      await openPayslips(tester);

      expect(find.text('August 2026'), findsWidgets);
      expect(find.text('Rp 10,615,000'), findsWidgets);
      expect(find.text('PS-202608-0006'), findsOneWidget);
      expect(find.text('Paid'), findsWidgets);
      await unmount(tester);
    });

    testWidgets('year filter asks the API for that year', (tester) async {
      final env = withPayslips();
      await pumpApp(tester, env);
      await openPayslips(tester);

      await tester.tap(find.text('2025'));
      await settle(tester, seconds: 1);

      expect(env.payslips.yearRequests, contains(2025));
      expect(find.text('December 2025'), findsOneWidget);
      expect(find.text('August 2026'), findsNothing);
      await unmount(tester);
    });

    testWidgets('empty state when nothing is released', (tester) async {
      await pumpApp(tester, TestEnv());
      await openPayslips(tester);
      expect(find.text('No payslips yet'), findsOneWidget);
      await unmount(tester);
    });

    testWidgets('error state with retry', (tester) async {
      final env = TestEnv(
        payslips: FakePayslipRepository()..listError = const ServerException(),
      );
      await pumpApp(tester, env);
      await openPayslips(tester);
      expect(find.text('Server problem'), findsOneWidget);
      env.payslips
        ..listError = null
        ..items = [FakePayslipRepository.sample()];
      await tester.tap(find.text('Try again'));
      await settle(tester, seconds: 1);
      expect(find.text('August 2026'), findsWidgets);
      await unmount(tester);
    });

    testWidgets(
      'detail: earnings with calculation hints, deductions, inputs, net',
      (tester) async {
        final env = withPayslips();
        await pumpApp(tester, env);
        await openPayslips(tester);
        await tester.tap(find.text('August 2026').first);
        await settle(tester, seconds: 1);

        expect(find.text('PT PeopleOS Demo Indonesia'), findsOneWidget);
        expect(find.text('Michael Carter'), findsOneWidget);
        expect(find.text('Basic Salary'), findsOneWidget);
        expect(find.text('Rp 9,000,000'), findsOneWidget);
        expect(find.text('19 days x 50,000'), findsOneWidget);
        expect(find.text('Cooperative Savings'), findsOneWidget);
        expect(find.text('19 of 20 days present'), findsOneWidget);
        expect(find.text('Overtime 2h'), findsOneWidget);
        expect(find.text('Net pay'), findsOneWidget);
        expect(find.text('Rp 10,615,000'), findsWidgets);
        await unmount(tester);
      },
    );

    testWidgets('PDF download goes through the repository and the exporter', (
      tester,
    ) async {
      final env = withPayslips();
      await pumpApp(tester, env);
      await openPayslips(tester);
      await tester.tap(find.text('August 2026').first);
      await settle(tester, seconds: 1);

      await tester.ensureVisible(find.text('Download PDF'));
      await tester.pump();
      await tester.tap(find.text('Download PDF'));
      await settle(tester, seconds: 1);

      expect(env.payslips.pdfRequests, [22]);
      expect(env.exporter.exported.single.fileName, 'PS-202608-0006.pdf');
      expect(find.text('Downloaded PS-202608-0006.pdf'), findsOneWidget);
      await unmount(tester);
    });

    testWidgets(
      'PDF failure shows a readable message and nothing is exported',
      (tester) async {
        final env = withPayslips();
        env.payslips.pdfError = const NotFoundException(
          'gone',
          code: 'NOT_FOUND',
        );
        await pumpApp(tester, env);
        await openPayslips(tester);
        await tester.tap(find.text('August 2026').first);
        await settle(tester, seconds: 1);
        await tester.ensureVisible(find.text('Download PDF'));
        await tester.pump();
        await tester.tap(find.text('Download PDF'));
        await settle(tester, seconds: 1);

        expect(find.text('This file is no longer available.'), findsOneWidget);
        expect(env.exporter.exported, isEmpty);
        await unmount(tester);
      },
    );
  });

  group('documents', () {
    TestEnv withDocs() => TestEnv(
      documents: FakeDocumentsRepository()
        ..items = [
          FakeDocumentsRepository.sample(documentJson()),
          FakeDocumentsRepository.sample(
            documentJson(
              id: 22,
              title: 'First Aid Certificate',
              status: 'EXPIRED',
              days: -50,
              expiry: '2026-08-21',
            ),
          ),
          FakeDocumentsRepository.sample(
            documentJson(
              id: 35,
              title: 'Employee Handbook',
              status: 'ACTIVE',
              days: null,
              expiry: null,
              company: true,
            ),
          ),
        ],
    );

    Future<void> openDocs(WidgetTester tester) async {
      await tester.tap(find.text('Profile').last);
      await settle(tester, seconds: 1);
      await tester.tap(find.text('Documents'));
      await settle(tester, seconds: 1);
    }

    testWidgets('status badges and days-until-expiry text (icon + text)', (
      tester,
    ) async {
      await pumpApp(tester, withDocs());
      await openDocs(tester);

      expect(find.text('National ID Card (reference copy)'), findsOneWidget);
      expect(find.text('Expiring'), findsWidgets);
      expect(find.text('Expires in 12 days'), findsOneWidget);
      expect(find.text('Expired'), findsWidgets);
      expect(find.text('Expired 50 days ago'), findsOneWidget);
      expect(find.text('Identification Reference'), findsWidgets);
      expect(find.textContaining('Company'), findsWidgets);
      await unmount(tester);
    });

    testWidgets('status filter', (tester) async {
      await pumpApp(tester, withDocs());
      await openDocs(tester);
      await tester.tap(find.text('Expired').first);
      await settle(tester, seconds: 1);
      expect(find.text('First Aid Certificate'), findsOneWidget);
      expect(find.text('Employee Handbook'), findsNothing);
      await unmount(tester);
    });

    testWidgets('403 shows access denied with retry', (tester) async {
      final env = TestEnv(
        documents: FakeDocumentsRepository()
          ..listError = const ForbiddenException('no', code: 'FORBIDDEN'),
      );
      await pumpApp(tester, env);
      await openDocs(tester);
      expect(find.text('Access denied'), findsOneWidget);
      expect(find.text('Try again'), findsOneWidget);
      await unmount(tester);
    });

    testWidgets(
      'detail download uses the repository (bearer path), 404 is graceful',
      (tester) async {
        final env = withDocs();
        await pumpApp(tester, env);
        await openDocs(tester);
        await tester.tap(find.text('National ID Card (reference copy)'));
        await settle(tester, seconds: 1);

        expect(find.text('Download / open'), findsOneWidget);
        expect(find.text('No expiry'), findsNothing);
        await tester.tap(find.text('Download / open'));
        await settle(tester, seconds: 1);
        expect(env.documents.downloads, [18]);
        expect(env.exporter.exported.single.fileName, 'National_ID.pdf');

        env.documents.downloadError = const NotFoundException(
          'x',
          code: 'FILE_MISSING',
        );
        await tester.tap(find.text('Download / open'));
        await settle(tester, seconds: 1);
        expect(find.textContaining('missing on the server'), findsOneWidget);
        await unmount(tester);
      },
    );

    testWidgets('empty state', (tester) async {
      await pumpApp(tester, TestEnv());
      await openDocs(tester);
      expect(find.text('No documents'), findsOneWidget);
      await unmount(tester);
    });
  });

  group('home updates strip', () {
    testWidgets('latest payslip and documents needing attention', (
      tester,
    ) async {
      final env = TestEnv(
        payslips: FakePayslipRepository(
          items: [FakePayslipRepository.sample()],
        ),
        documents: FakeDocumentsRepository()
          ..items = [
            FakeDocumentsRepository.sample(documentJson()),
            FakeDocumentsRepository.sample(
              documentJson(
                id: 22,
                title: 'First Aid',
                status: 'EXPIRED',
                days: -50,
              ),
            ),
          ],
      );
      await pumpApp(tester, env);
      await tester.scrollUntilVisible(
        find.text('Updates'),
        300,
        scrollable: find.byType(Scrollable).first,
      );

      expect(find.text('Updates'), findsOneWidget);
      expect(find.text('Payslip available'), findsOneWidget);
      expect(find.text('August 2026 · Rp 10,615,000'), findsOneWidget);
      expect(find.text('2 documents need attention'), findsOneWidget);
      expect(find.text('1 expiring soon · 1 expired'), findsOneWidget);
      await unmount(tester);
    });

    testWidgets('nothing to report: no Updates section', (tester) async {
      await pumpApp(tester, TestEnv());
      expect(find.text('Updates'), findsNothing);
      await unmount(tester);
    });
  });

  group('notification deep links', () {
    AppNotification n(Map<String, dynamic> json) =>
        AppNotification.fromJson(JsonReader(json));

    testWidgets('payslip.available opens that payslip', (tester) async {
      final env = withPayslips();
      env.notifications.items = [
        n(
          notificationJson(
            id: 5,
            type: 'payslip.available',
            title: 'Payslip available',
            entityType: r'App\Models\Payslip',
            entityId: 22,
          ),
        ),
      ];
      await pumpApp(tester, env);
      await tester.tap(find.byTooltip('Notifications'));
      await settle(tester, seconds: 1);
      await tester.tap(find.text('Payslip available').last);
      await settle(tester, seconds: 1);
      expect(find.text('Download PDF'), findsOneWidget);
      expect(find.text('PT PeopleOS Demo Indonesia'), findsOneWidget);
      await unmount(tester);
    });

    testWidgets('document.expiring opens that document', (tester) async {
      final env = TestEnv(
        documents: FakeDocumentsRepository()
          ..items = [FakeDocumentsRepository.sample(documentJson())],
      );
      env.notifications.items = [
        n(
          notificationJson(
            id: 6,
            type: 'document.expiring',
            title: 'Document expiring',
            entityType: r'App\Models\Document',
            entityId: 18,
          ),
        ),
      ];
      await pumpApp(tester, env);
      await tester.tap(find.byTooltip('Notifications'));
      await settle(tester, seconds: 1);
      await tester.tap(find.text('Document expiring').last);
      await settle(tester, seconds: 1);
      expect(find.text('Download / open'), findsOneWidget);
      expect(find.text('Expires in 12 days'), findsWidgets);
      await unmount(tester);
    });
  });
}
