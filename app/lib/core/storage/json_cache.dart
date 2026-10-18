import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Tiny JSON read-cache used to show the last known data while the server is
/// unreachable. Every entry remembers when it was written, and serving one is
/// always announced through [staleSince] so the UI can say "saved data from
/// 5 minutes ago" instead of presenting it as fresh.
class JsonCache {
  JsonCache(this._prefs);

  final SharedPreferences _prefs;

  /// Time of the oldest cached payload served since the last successful live
  /// read; null while everything on screen is live.
  final ValueNotifier<DateTime?> staleSince = ValueNotifier(null);

  ({Object? value, DateTime? at})? _entry(String key) {
    final raw = _prefs.getString('cache.$key');
    if (raw == null) return null;
    try {
      final decoded = jsonDecode(raw);
      if (decoded is Map &&
          decoded.containsKey('v') &&
          decoded.containsKey('t')) {
        return (value: decoded['v'], at: DateTime.tryParse('${decoded['t']}'));
      }
      return (value: decoded, at: null); // legacy entry without a timestamp
    } catch (_) {
      return null;
    }
  }

  Object? read(String key) => _entry(key)?.value;

  /// When [key] was cached (null if unknown or missing).
  DateTime? writtenAt(String key) => _entry(key)?.at;

  Future<void> write(String key, Object? value) => _prefs.setString(
    'cache.$key',
    jsonEncode({'t': DateTime.now().toUtc().toIso8601String(), 'v': value}),
  );

  Future<void> remove(String key) => _prefs.remove('cache.$key');

  /// Records that a cached payload written at [at] was shown.
  void markStale(DateTime? at) {
    final current = staleSince.value;
    final when = at ?? DateTime.now().toUtc();
    if (current == null || when.isBefore(current)) staleSince.value = when;
  }

  /// A live read succeeded: whatever is on screen is fresh again.
  void markFresh() => staleSince.value = null;

  /// Drops every cached value (used on logout so another user never sees it).
  Future<void> clear() async {
    for (final k in _prefs.getKeys().where((k) => k.startsWith('cache.'))) {
      await _prefs.remove(k);
    }
    staleSince.value = null;
  }
}
