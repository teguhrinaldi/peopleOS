/// An instant plus the UTC offset it was expressed in by the server.
///
/// The API returns ISO-8601 strings carrying the company offset
/// (`2026-09-30T08:42:00+07:00`). `DateTime.parse` normalises those to UTC and
/// loses the offset, so we keep it to render company wall-clock time without a
/// timezone database.
class ZonedTime {
  const ZonedTime(this.utc, this.offset);

  final DateTime utc;
  final Duration offset;

  static final _offsetRe = RegExp(r'([+-])(\d{2}):?(\d{2})$');

  static ZonedTime? tryParse(Object? raw) {
    if (raw is! String || raw.isEmpty) return null;
    final parsed = DateTime.tryParse(raw);
    if (parsed == null) return null;
    var offset = Duration.zero;
    final m = _offsetRe.firstMatch(raw);
    if (m != null) {
      final sign = m.group(1) == '-' ? -1 : 1;
      offset =
          Duration(
            hours: int.parse(m.group(2)!),
            minutes: int.parse(m.group(3)!),
          ) *
          sign;
    }
    return ZonedTime(parsed.toUtc(), offset);
  }

  /// Builds an instant from company wall-clock fields and the company offset.
  factory ZonedTime.fromWall(
    DateTime date,
    int hour,
    int minute,
    Duration offset,
  ) => ZonedTime(
    DateTime.utc(
      date.year,
      date.month,
      date.day,
      hour,
      minute,
    ).subtract(offset),
    offset,
  );

  /// Wall-clock fields in the company timezone, carried by a UTC DateTime so
  /// no device timezone can shift them.
  DateTime get wall => utc.add(offset);

  String get hhmm =>
      '${wall.hour.toString().padLeft(2, '0')}:${wall.minute.toString().padLeft(2, '0')}';

  /// Date part (yyyy-MM-dd) in the company timezone.
  String get dateKey => formatDateOnly(wall);

  String toIso() {
    final total = offset.inMinutes;
    final sign = total < 0 ? '-' : '+';
    final abs = total.abs();
    final tz =
        '$sign${(abs ~/ 60).toString().padLeft(2, '0')}:${(abs % 60).toString().padLeft(2, '0')}';
    final w = wall;
    String p(int v, [int n = 2]) => v.toString().padLeft(n, '0');
    return '${p(w.year, 4)}-${p(w.month)}-${p(w.day)}T${p(w.hour)}:${p(w.minute)}:${p(w.second)}$tz';
  }

  @override
  bool operator ==(Object other) =>
      other is ZonedTime && other.utc == utc && other.offset == offset;

  @override
  int get hashCode => Object.hash(utc, offset);
}

/// `2026-09-30` -> DateTime (date only, no timezone semantics).
DateTime? parseDateOnly(Object? raw) {
  if (raw is! String || raw.length < 10) return null;
  final y = int.tryParse(raw.substring(0, 4));
  final m = int.tryParse(raw.substring(5, 7));
  final d = int.tryParse(raw.substring(8, 10));
  if (y == null || m == null || d == null) return null;
  return DateTime(y, m, d);
}

String formatDateOnly(DateTime d) =>
    '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
