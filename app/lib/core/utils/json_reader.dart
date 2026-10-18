import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/utils/zoned_time.dart';

/// Lenient, null-safe accessors over decoded JSON maps. A missing optional
/// field never crashes the UI; a missing required one raises a clear error.
class JsonReader {
  const JsonReader(this.map);

  final Map<String, dynamic> map;

  static JsonReader of(Object? raw) {
    if (raw is Map) return JsonReader(Map<String, dynamic>.from(raw));
    throw const UnknownException('Unexpected response shape.', 'BAD_SHAPE');
  }

  static JsonReader? tryOf(Object? raw) =>
      raw is Map ? JsonReader(Map<String, dynamic>.from(raw)) : null;

  String? str(String key) {
    final v = map[key];
    return v?.toString();
  }

  String requireStr(String key) =>
      str(key) ??
      (throw UnknownException('Missing field "$key".', 'BAD_SHAPE'));

  int? intOrNull(String key) {
    final v = map[key];
    if (v is int) return v;
    if (v is num) return v.toInt();
    if (v is String) return int.tryParse(v);
    return null;
  }

  int intOr(String key, int fallback) => intOrNull(key) ?? fallback;

  double? doubleOrNull(String key) {
    final v = map[key];
    if (v is num) return v.toDouble();
    if (v is String) return double.tryParse(v);
    return null;
  }

  bool boolOr(String key, bool fallback) {
    final v = map[key];
    if (v is bool) return v;
    if (v is num) return v != 0;
    return fallback;
  }

  JsonReader? obj(String key) => tryOf(map[key]);

  List<JsonReader> objects(String key) {
    final v = map[key];
    if (v is! List) return const [];
    return v.whereType<Map<dynamic, dynamic>>().map(JsonReader.of).toList();
  }

  ZonedTime? time(String key) => ZonedTime.tryParse(map[key]);

  DateTime? date(String key) => parseDateOnly(map[key]);
}
