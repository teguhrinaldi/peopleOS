import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/l10n/generated/app_localizations_en.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/storage/app_settings.dart';
import 'package:peopleos/core/utils/money.dart';
import 'package:peopleos/features/demo/demo_backend.dart';
import 'package:peopleos/features/documents/data/demo_documents_repository.dart';
import 'package:peopleos/features/documents/domain/document.dart';
import 'package:peopleos/features/payslip/data/demo_payslip_repository.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  late DemoPayslipRepository payslips;
  late DemoDocumentsRepository docs;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();
    final backend = DemoBackend(
      prefs: prefs,
      settings: AppSettings(prefs),
      clock: () => DateTime(2026, 10, 10, 9),
      latency: Duration.zero,
    );
    payslips = DemoPayslipRepository(backend);
    docs = DemoDocumentsRepository(backend);
  });

  test('payslips: last completed months, paid, newest first', () async {
    final page = await payslips.list();
    expect(page.items.map((p) => p.period.name), [
      'September 2026',
      'August 2026',
      'July 2026',
    ]);
    expect(page.items.every((p) => p.isPaid && p.hasPdf), isTrue);
    expect(page.items.first.number, 'PS-202609-0006');
  });

  test('payslip numbers add up exactly: earnings - deductions = net', () async {
    for (final summary in (await payslips.list()).items) {
      final p = await payslips.detail(summary.id);
      final earnings = p.earnings.fold(
        Money.parse('0'),
        (s, l) => s + l.amount,
      );
      final deductions = p.deductions.fold(
        Money.parse('0'),
        (s, l) => s + l.amount,
      );
      expect(earnings, p.gross, reason: p.number);
      expect(deductions, p.deductionsTotal);
      expect(earnings - deductions, p.net);
      expect(
        p.basicSalary,
        Money.parse('9000000'),
        reason: 'Michael: 9,000,000 from July',
      );
      expect(
        p.earnings.any((l) => l.description?.contains(' x 50,000') ?? false),
        isTrue,
      );
    }
  });

  test('year filter and unknown payslip', () async {
    expect((await payslips.list(year: 2025)).items, isEmpty);
    expect((await payslips.list(year: 2026)).items, hasLength(3));
    await expectLater(payslips.detail(999), throwsA(isA<NotFoundException>()));
  });

  test('demo payslip PDF is a real PDF with the figures', () async {
    final p = (await payslips.list()).items.first;
    final file = await payslips.pdf(p);
    final text = String.fromCharCodes(file.bytes);
    expect(file.fileName, 'PS-202609-0006.pdf');
    expect(text.startsWith('%PDF-1.4'), isTrue);
    expect(text, contains('Basic Salary'));
    expect(text, contains('Net pay'));
  });

  test('documents: statuses computed from the demo clock', () async {
    final all = (await docs.list()).items;
    EmployeeDocument byTitle(String t) =>
        all.firstWhere((d) => d.title.startsWith(t));

    final id = byTitle('National ID');
    expect(id.status, DocumentStatus.expiring);
    expect(id.daysUntilExpiry, 12);
    expect(id.expiryText(AppLocalizationsEn()), 'Expires in 12 days');

    final firstAid = byTitle('First Aid');
    expect(firstAid.status, DocumentStatus.expired);
    expect(firstAid.daysUntilExpiry, -50);

    expect(byTitle('Employment Contract').status, DocumentStatus.active);
    expect(byTitle('Holiday Calendar').status, DocumentStatus.active);
    expect(byTitle('Employee Handbook').isCompany, isTrue);
  });

  test('documents: HR-only items never appear and answer 404', () async {
    final all = (await docs.list()).items;
    expect(
      all.any((d) => d.title.toLowerCase().contains('disciplinary')),
      isFalse,
    );
    expect(all.any((d) => d.status == DocumentStatus.archived), isFalse);
    await expectLater(docs.detail(99), throwsA(isA<NotFoundException>()));
  });

  test('documents: status and type filters', () async {
    final expiring = (await docs.list(status: DocumentStatus.expiring)).items;
    expect(expiring.map((d) => d.id), [18]);
    final certs = (await docs.list(typeId: 3)).items;
    expect(certs.every((d) => d.type.code == 'CERTIFICATE'), isTrue);
    expect(certs, hasLength(2));
    expect((await docs.types()).map((t) => t.code), contains('PAYSLIP'));
  });

  test('document download is a PDF named after the file', () async {
    final d = (await docs.list()).items.first;
    final file = await docs.download(d);
    expect(file.fileName.endsWith('.pdf'), isTrue);
    expect(String.fromCharCodes(file.bytes).startsWith('%PDF'), isTrue);
  });
}
