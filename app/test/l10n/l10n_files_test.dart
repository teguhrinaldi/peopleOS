import 'dart:convert';
import 'dart:io';
import 'dart:ui';

import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/core/l10n/locale_controller.dart';
import 'package:peopleos/core/utils/money.dart';
import 'package:peopleos/l10n/generated/app_localizations_en.dart';
import 'package:peopleos/l10n/generated/app_localizations_id.dart';

Map<String, dynamic> arb(String lang) =>
    jsonDecode(File('lib/l10n/app_$lang.arb').readAsStringSync())
        as Map<String, dynamic>;

Set<String> placeholders(String message) => {
  for (final m in RegExp(r'\{(\w+)(?:,|\})').allMatches(message)) m.group(1)!,
};

void main() {
  final en = arb('en');
  final id = arb('id');
  final enKeys = en.keys.where((k) => !k.startsWith('@')).toList();
  final idKeys = id.keys.where((k) => !k.startsWith('@')).toSet();

  test('there is a real catalogue (not a stub)', () {
    expect(enKeys.length, greaterThan(400));
  });

  test('every English key exists in Bahasa Indonesia', () {
    final missing = enKeys.where((k) => !idKeys.contains(k)).toList();
    expect(missing, isEmpty, reason: 'missing in app_id.arb: $missing');
  });

  test('Indonesian has no orphan keys', () {
    final extra = idKeys.where((k) => !en.containsKey(k)).toList();
    expect(extra, isEmpty, reason: 'only in app_id.arb: $extra');
  });

  test('both languages use the same placeholders per key', () {
    final problems = <String>[];
    for (final k in enKeys) {
      if (!idKeys.contains(k)) continue;
      final a = placeholders(en[k] as String);
      final b = placeholders(id[k] as String);
      // ICU plurals mention the variable once as the selector; compare sets.
      if (a.difference(b).isNotEmpty || b.difference(a).isNotEmpty) {
        problems.add('$k: en=$a id=$b');
      }
    }
    expect(problems, isEmpty);
  });

  test('no empty values and Indonesian is really translated', () {
    final empty = [
      for (final k in enKeys)
        if ((en[k] as String).trim().isEmpty ||
            (id[k] as String? ?? '').trim().isEmpty)
          k,
    ];
    expect(empty, isEmpty);
    // Identical strings are legitimate for names, units and short tokens, but
    // a large share of identical text means untranslated content.
    final same = [
      for (final k in enKeys)
        if (en[k] == id[k] && (en[k] as String).length > 12) k,
    ];
    expect(
      same.length / enKeys.length,
      lessThan(0.06),
      reason: 'suspiciously many untranslated long strings: ${same.take(20)}',
    );
  });

  test('generated classes exist for both languages and differ', () {
    final e = AppLocalizationsEn();
    final i = AppLocalizationsId();
    expect(e.appName, 'PeopleOS');
    expect(i.languageSystem, isNot(e.languageSystem));
    expect(e.ageMinutes(5), '5 minutes ago');
    expect(i.ageMinutes(5), '5 menit lalu');
    expect(e.ageMinutes(1), '1 minute ago');
  });

  group('locale resolution (device default, English fallback)', () {
    test('supported device languages are used', () {
      expect(resolveAppLocale(const Locale('id', 'ID')).languageCode, 'id');
      expect(resolveAppLocale(const Locale('en', 'GB')).languageCode, 'en');
    });
    test('unsupported or missing device languages fall back to English', () {
      expect(resolveAppLocale(const Locale('fr')).languageCode, 'en');
      expect(resolveAppLocale(null).languageCode, 'en');
    });
  });

  group('money formatting follows the locale, never the stored value', () {
    test('Indonesian separators in id', () {
      expect(Money.parse('10615000.00').format(locale: 'id'), 'Rp 10.615.000');
      expect(
        Money.parse('1250.50')
            .format(currency: 'USD', decimals: 2, locale: 'id'),
        'USD 1.250,50',
      );
    });
    test('English separators in en', () {
      expect(Money.parse('10615000.00').format(locale: 'en'), 'Rp 10,615,000');
      expect(
        Money.parse('1250.50')
            .format(currency: 'USD', decimals: 2, locale: 'en'),
        'USD 1,250.50',
      );
    });
    test('the underlying amount is identical in both', () {
      final m = Money.parse('9400000.00');
      expect(m, Money.parse('9400000'));
      m.format(locale: 'id');
      m.format(locale: 'en');
      expect(m, Money.parse('9400000.00'));
    });
  });
}
