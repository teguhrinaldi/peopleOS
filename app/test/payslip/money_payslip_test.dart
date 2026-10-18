import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/l10n/generated/app_localizations_en.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/core/utils/money.dart';
import 'package:peopleos/features/demo/demo_pdf.dart';
import 'package:peopleos/features/documents/domain/document.dart';
import 'package:peopleos/features/payslip/domain/payslip.dart';

import '../helpers/batch2_json.dart';

void main() {
  group('Money (decimal strings, no floating point)', () {
    test('parses and formats IDR without decimals', () {
      expect(Money.parse('10615000.00').format(locale: 'id'), 'Rp 10.615.000');
      expect(Money.parse('0.00').format(locale: 'id'), 'Rp 0');
      expect(Money.parse('999').format(locale: 'id'), 'Rp 999');
      expect(Money.parse('1000').format(locale: 'id'), 'Rp 1.000');
    });

    test('keeps cents exactly, rounds half up to the payslip decimals', () {
      expect(
        Money.parse('1250.50').format(currency: 'USD', decimals: 2),
        'USD 1,250.50',
      );
      expect(
        Money.parse('1250.50')
            .format(currency: 'IDR', decimals: 0, locale: 'id'),
        'Rp 1.251',
      );
      expect(
        Money.parse('1250.49')
            .format(currency: 'IDR', decimals: 0, locale: 'id'),
        'Rp 1.250',
      );
      expect(
        Money.parse('0.10').format(currency: 'USD', decimals: 2),
        'USD 0.10',
      );
    });

    test('no float drift when summing', () {
      var sum = Money.parse('0');
      for (var i = 0; i < 10; i++) {
        sum += Money.parse('0.10');
      }
      expect(sum, Money.parse('1.00'));
      expect(Money.parse('0.30') - Money.parse('0.10'), Money.parse('0.20'));
    });

    test('negative amounts and huge values', () {
      expect(Money.parse('-100000.00').format(locale: 'id'), '-Rp 100.000');
      expect(Money.parse('-100000.00').isNegative, isTrue);
      expect(
        Money.parse('99999999999999.99').format(currency: 'USD', decimals: 2),
        'USD 99,999,999,999,999.99',
      );
    });
  });

  test('payslip list object (docs/api-payroll.md shape)', () {
    final p = Payslip.fromJson(JsonReader(payslipSummaryJson()));
    expect(p.number, 'PS-202608-0006');
    expect(p.period.name, 'August 2026');
    expect(p.period.year, 2026);
    expect(p.isPaid, isTrue);
    expect(p.hasPdf, isTrue);
    expect(p.money(p.net, 'id'), 'Rp 10.615.000');
    expect(p.hasDetail, isFalse);
  });

  test('payslip detail with earnings, hints, inputs and company', () {
    final p = Payslip.fromJson(JsonReader(payslipDetailJson()));
    expect(p.hasDetail, isTrue);
    expect(p.companyName, 'PT PeopleOS Demo Indonesia');
    expect(p.employee!.name, 'Michael Carter');
    expect(p.inputs!.presentDays, 19);
    expect(p.inputs!.overtimeMinutes, 120);
    expect(p.earnings.map((e) => e.code), ['BASIC', 'TRANSPORT', 'OVERTIME']);
    expect(p.earnings[1].description, '19 days x 50,000');
    expect(p.deductions.single.amount, Money.parse('100000'));
    // Lines add up exactly to the stated gross.
    final sum = p.earnings.fold(Money.parse('0'), (s, l) => s + l.amount);
    expect(sum, Money.parse('10050000.00'));
    expect(p.basicSalary, Money.parse('9000000'));
  });

  test('payslip money uses the payslips own currency and decimals', () {
    final p = Payslip.fromJson(
      JsonReader(
        payslipSummaryJson(currency: 'USD', decimals: 2, net: '1250.50'),
      ),
    );
    expect(p.money(p.net), 'USD 1,250.50');
  });

  group('documents', () {
    test('status and expiry text from days_until_expiry', () {
      EmployeeDocument d(String s, int? days) => EmployeeDocument.fromJson(
        JsonReader(documentJson(status: s, days: days)),
      );
      expect(
        d('EXPIRING', 12).expiryText(AppLocalizationsEn()),
        'Expires in 12 days',
      );
      expect(
        d('EXPIRING', 1).expiryText(AppLocalizationsEn()),
        'Expires tomorrow',
      );
      expect(
        d('EXPIRING', 0).expiryText(AppLocalizationsEn()),
        'Expires today',
      );
      expect(
        d('EXPIRED', -5).expiryText(AppLocalizationsEn()),
        'Expired 5 days ago',
      );
      expect(
        d('EXPIRED', -1).expiryText(AppLocalizationsEn()),
        'Expired 1 day ago',
      );
      expect(d('ACTIVE', null).expiryText(AppLocalizationsEn()), isNull);
      expect(d('ARCHIVED', 30).expiryText(AppLocalizationsEn()), isNull);
      expect(DocumentStatus.parse('expiring'), DocumentStatus.expiring);
    });

    test('document object never exposes a storage path', () {
      final d = EmployeeDocument.fromJson(JsonReader(documentJson()));
      expect(d.downloadUrl, '/api/v1/documents/18/download');
      expect(d.fileName, 'National_ID.pdf');
      expect(d.type.name, 'Identification Reference');
      expect(formatFileSize(AppLocalizationsEn(), 2367), '2 KB');
      expect(formatFileSize(AppLocalizationsEn(), 5 * 1024 * 1024), '5.0 MB');
    });
  });

  test('demo PDF is a structurally valid PDF', () {
    final bytes = buildSimplePdf(
      title: 'Payslip (test)',
      lines: ['Net pay: IDR 10615000.00'],
    );
    final text = String.fromCharCodes(bytes);
    expect(text.startsWith('%PDF-1.4'), isTrue);
    expect(text.trimRight().endsWith('%%EOF'), isTrue);
    expect(text, contains('Net pay: IDR 10615000.00'));
    expect(text, contains(r'Payslip \(test\)'));
    // The xref offset of object 1 points at "1 0 obj".
    final xrefPos = int.parse(
      RegExp(r'startxref\n(\d+)').firstMatch(text)!.group(1)!,
    );
    expect(text.substring(xrefPos, xrefPos + 4), 'xref');
  });
}
