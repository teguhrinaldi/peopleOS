import 'package:intl/intl.dart';

// Language-neutral helpers only. Dates, durations, greetings and money are
// localized through `context.fmt` / `lib/l10n/l10n.dart`.

/// 4930s -> "01:22:10".
String formatClock(Duration d) {
  final s = d.inSeconds < 0 ? 0 : d.inSeconds;
  String p(int v) => v.toString().padLeft(2, '0');
  return '${p(s ~/ 3600)}:${p((s % 3600) ~/ 60)}:${p(s % 60)}';
}

/// 1234 -> "1.2 km" (en) / "1,2 km" (id); 87.4 -> "87 m".
String formatDistance(double meters, {String locale = 'en'}) {
  if (meters >= 1000) {
    final km = NumberFormat('0.0', locale).format(meters / 1000);
    return '$km km';
  }
  return '${meters.round()} m';
}

String initialsOf(String name) {
  final parts = name
      .trim()
      .split(RegExp(r'\s+'))
      .where((p) => p.isNotEmpty)
      .toList();
  if (parts.isEmpty) return '?';
  final first = parts.first[0];
  final last = parts.length > 1 ? parts.last[0] : '';
  return (first + last).toUpperCase();
}
