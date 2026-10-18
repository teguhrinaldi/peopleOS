import 'package:peopleos/l10n/generated/app_localizations.dart';

/// "5 minutes ago" / "5 menit lalu" for a past instant.
String ageText(AppLocalizations l10n, DateTime at, {DateTime? now}) {
  final d = (now ?? DateTime.now().toUtc()).difference(at.toUtc());
  if (d.inMinutes < 1) return l10n.ageJustNow;
  if (d.inMinutes < 60) return l10n.ageMinutes(d.inMinutes);
  if (d.inHours < 24) return l10n.ageHours(d.inHours);
  return l10n.ageDays(d.inDays);
}
