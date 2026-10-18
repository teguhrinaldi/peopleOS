import 'package:flutter/widgets.dart';
import 'package:intl/intl.dart';
import 'package:peopleos/core/utils/money.dart';
import 'package:peopleos/l10n/generated/app_localizations.dart';

export 'package:peopleos/l10n/generated/app_localizations.dart';

/// `context.l10n.someKey` for text, `context.fmt` for dates and numbers.
/// Both depend on the active [Locale], so widgets rebuild when the user
/// switches language at runtime.
extension L10nContext on BuildContext {
  AppLocalizations get l10n => AppLocalizations.of(this);
  Formats get fmt => Formats(Localizations.localeOf(this));
}

/// Locale-aware date, number and money formatting (intl). Stored values are
/// never touched: only their display changes.
class Formats {
  const Formats(this.locale);

  final Locale locale;

  /// "en" / "id", the tag intl and the money formatter understand.
  String get tag => locale.languageCode;

  /// "Wed, Sep 30" (en) / "Rab, 30 Sep" (id).
  String weekdayShort(DateTime d) =>
      DateFormat(tag == 'en' ? 'EEE, MMM d' : 'EEE, d MMM', tag).format(d);
  String weekdayLong(DateTime d) =>
      DateFormat('EEEE, d MMMM yyyy', tag).format(d);
  String monthYear(DateTime d) => DateFormat('MMMM yyyy', tag).format(d);
  String shortDate(DateTime d) => DateFormat('d MMM yyyy', tag).format(d);
  String shortDateNoYear(DateTime d) => DateFormat('d MMM', tag).format(d);
  String dayMonth(DateTime d) => DateFormat('d MMM', tag).format(d);
  String chipDate(DateTime d) => DateFormat('EEE d MMM', tag).format(d);

  /// Money with the payslip's currency and decimals ("Rp 10.615.000" in id,
  /// "Rp 10,615,000" in en).
  String money(Money m, {String currency = 'IDR', int decimals = 0}) =>
      m.format(currency: currency, decimals: decimals, locale: tag);

  /// Plain number with locale separators ("1.250,5" in id).
  String number(num v, {int decimals = 0}) => NumberFormat.decimalPatternDigits(
    locale: tag,
    decimalDigits: decimals,
  ).format(v);
}

/// "2h 5m" / "2j 5m", "45m", "0m".
String formatDurationL10n(AppLocalizations l, int minutes) {
  if (minutes <= 0) return l.durationM(0);
  final h = minutes ~/ 60;
  final m = minutes % 60;
  if (h == 0) return l.durationM(m);
  return m == 0 ? l.durationH(h) : l.durationHm(h, m);
}

String greetingL10n(AppLocalizations l, int hour) {
  if (hour < 11) return l.greetingMorning;
  if (hour < 15) return l.greetingAfternoon;
  if (hour < 19) return l.greetingEvening;
  return l.greetingNight;
}

/// 0.5 -> "0.5 days" (en) / "0,5 hari" (id); 1 -> "1 day".
String daysL10n(AppLocalizations l, double days, String locale) {
  final text = days == days.roundToDouble()
      ? days.toStringAsFixed(0)
      : NumberFormat.decimalPatternDigits(
          locale: locale,
          decimalDigits: 1,
        ).format(days);
  return days == 1 ? l.daysOne(text) : l.daysMany(text);
}
