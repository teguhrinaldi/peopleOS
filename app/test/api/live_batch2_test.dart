@Timeout(Duration(minutes: 2))
library;

import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/l10n/generated/app_localizations_en.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/network/api_client.dart';
import 'package:peopleos/core/storage/json_cache.dart';
import 'package:peopleos/core/storage/token_store.dart';
import 'package:peopleos/core/utils/money.dart';
import 'package:peopleos/features/auth/data/api_auth_repository.dart';
import 'package:peopleos/features/documents/data/api_documents_repository.dart';
import 'package:peopleos/features/documents/domain/document.dart';
import 'package:peopleos/features/payslip/data/api_payslip_repository.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Payslips + documents against a live server (Michael by default).
///   PEOPLEOS_API_TESTS=1 flutter test test/api/live_batch2_test.dart
/// Read-only: nothing is written to the database.
void main() {
  final enabled = Platform.environment['PEOPLEOS_API_TESTS'] == '1';
  final baseUrl =
      Platform.environment['PEOPLEOS_API_URL'] ?? 'http://127.0.0.1:8000';
  final email =
      Platform.environment['PEOPLEOS_API_USER3'] ?? 'employee@peopleos.test';

  test(
    'payslips: list, detail, PDF; documents: list, filters, download, 404',
    () async {
      HttpOverrides.global = null;
      SharedPreferences.setMockInitialValues({});
      final prefs = await SharedPreferences.getInstance();
      final tokens = MemoryTokenStore();
      final client = ApiClient.create(
        readToken: tokens.read,
        baseUrl: () => baseUrl,
        onUnauthorized: () {},
      );
      final cache = JsonCache(prefs);
      final auth = ApiAuthRepository(
        client: client,
        tokens: tokens,
        cache: cache,
      );
      await auth.login(email: email, password: 'password');

      // ---- payslips --------------------------------------------------------
      final payslips = ApiPayslipRepository(client: client, cache: cache);
      final page = await payslips.list();
      // ignore: avoid_print
      print(
        'PAYSLIPS total=${page.total} first=${page.items.first.number} '
        'net=${page.items.first.money(page.items.first.net)}',
      );
      expect(page.items, isNotEmpty);
      expect(
        page.items.every((p) => p.status == 'PAID' || p.status == 'FINALIZED'),
        isTrue,
      );

      final detail = await payslips.detail(page.items.first.id);
      // ignore: avoid_print
      print(
        'DETAIL ${detail.period.name}: ${detail.earnings.length} earnings, '
        '${detail.deductions.length} deductions, '
        'hints=${detail.earnings.map((e) => e.description).whereType<String>().join(' | ')}',
      );
      final earned = detail.earnings.fold(
        Money.parse('0'),
        (s, l) => s + l.amount,
      );
      expect(earned, detail.gross, reason: 'earnings sum to gross exactly');
      expect(detail.gross - detail.deductionsTotal, detail.net);
      expect(detail.inputs!.presentDays, isNotNull);

      final year = detail.period.year!;
      expect((await payslips.list(year: year)).items, isNotEmpty);
      expect((await payslips.list(year: 2001)).items, isEmpty);

      final pdf = await payslips.pdf(detail);
      // ignore: avoid_print
      print('PDF ${pdf.fileName} ${pdf.bytes.length} bytes');
      expect(String.fromCharCodes(pdf.bytes.take(5)), '%PDF-');

      await expectLater(
        payslips.detail(999999),
        throwsA(isA<NotFoundException>()),
      );

      // ---- documents -------------------------------------------------------
      final docs = ApiDocumentsRepository(client: client, cache: cache);
      final types = await docs.types();
      expect(types.map((t) => t.code), contains('EMPLOYMENT_CONTRACT'));
      final list = await docs.list();
      // ignore: avoid_print
      print(
        'DOCUMENTS total=${list.total} '
        '${list.items.map((d) => '${d.title} [${d.status.wire} ${d.expiryText(AppLocalizationsEn()) ?? ''}]').join('; ')}',
      );
      expect(list.items, isNotEmpty);
      expect(
        list.items.any((d) => d.title.toLowerCase().contains('disciplinary')),
        isFalse,
        reason: 'HR-only documents must not be visible',
      );
      expect(
        list.items.every((d) => d.status != DocumentStatus.archived),
        isTrue,
      );

      final expiring = await docs.list(status: DocumentStatus.expiring);
      expect(
        expiring.items.every((d) => d.status == DocumentStatus.expiring),
        isTrue,
      );

      final doc = list.items.first;
      final one = await docs.detail(doc.id);
      expect(one.id, doc.id);
      final file = await docs.download(one);
      // ignore: avoid_print
      print(
        'DOWNLOAD ${file.fileName} ${file.bytes.length} bytes ${file.mimeType}',
      );
      expect(file.bytes, isNotEmpty);
      expect(String.fromCharCodes(file.bytes.take(4)), '%PDF');

      await expectLater(docs.detail(999999), throwsA(isA<NotFoundException>()));
      await auth.logout();
    },
    skip: enabled
        ? false
        : 'Set PEOPLEOS_API_TESTS=1 to run against a live server',
  );
}
