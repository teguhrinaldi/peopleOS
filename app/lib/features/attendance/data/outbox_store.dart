import 'dart:async';
import 'dart:convert';

import 'package:peopleos/features/attendance/domain/outbox_item.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Durable local queue of attendance actions. Writes are serialized so two
/// quick taps or a sync racing a new action cannot lose an entry.
abstract class OutboxStore {
  Future<List<OutboxItem>> all();
  Future<void> upsert(OutboxItem item);
  Future<void> remove(String idempotencyKey);
}

class PrefsOutboxStore implements OutboxStore {
  PrefsOutboxStore(this._prefs);

  static const _key = 'outbox.v1';
  final SharedPreferences _prefs;
  Future<void> _tail = Future.value();

  List<OutboxItem> _read() {
    final raw = _prefs.getString(_key);
    if (raw == null) return [];
    try {
      return (jsonDecode(raw) as List)
          .map((e) => OutboxItem.fromJson(Map<String, dynamic>.from(e as Map)))
          .toList();
    } catch (_) {
      // Never throw away a corrupt queue silently: keep the raw text aside.
      unawaited(_prefs.setString('$_key.corrupt', raw));
      return [];
    }
  }

  Future<void> _write(List<OutboxItem> items) =>
      _prefs.setString(_key, jsonEncode(items.map((e) => e.toJson()).toList()));

  Future<T> _serial<T>(Future<T> Function() op) {
    final next = _tail.then((_) => op());
    _tail = next.then((_) {}, onError: (_) {});
    return next;
  }

  @override
  Future<List<OutboxItem>> all() => _serial(() async => _read());

  @override
  Future<void> upsert(OutboxItem item) => _serial(() async {
    final items = _read();
    final i = items.indexWhere((e) => e.idempotencyKey == item.idempotencyKey);
    if (i >= 0) {
      items[i] = item;
    } else {
      items.add(item);
    }
    await _write(items);
  });

  @override
  Future<void> remove(String idempotencyKey) => _serial(() async {
    final items = _read()
      ..removeWhere((e) => e.idempotencyKey == idempotencyKey);
    await _write(items);
  });
}

class MemoryOutboxStore implements OutboxStore {
  final List<OutboxItem> _items = [];

  @override
  Future<List<OutboxItem>> all() async => List.of(_items);

  @override
  Future<void> upsert(OutboxItem item) async {
    final i = _items.indexWhere((e) => e.idempotencyKey == item.idempotencyKey);
    if (i >= 0) {
      _items[i] = item;
    } else {
      _items.add(item);
    }
  }

  @override
  Future<void> remove(String idempotencyKey) async =>
      _items.removeWhere((e) => e.idempotencyKey == idempotencyKey);
}
