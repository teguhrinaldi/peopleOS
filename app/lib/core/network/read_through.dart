import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/storage/json_cache.dart';

/// Fetch-then-cache for idempotent reads. When the network is unreachable
/// (or the server faults) the last cached payload is parsed instead, flagged
/// `stale`, and announced through [JsonCache.staleSince] so the UI shows its
/// age. A business error (4xx) is never replaced by cached data.
Future<T> readThrough<T>({
  required JsonCache cache,
  required String key,
  required Future<Object?> Function() fetch,
  required T Function(Object? raw, {required bool stale}) parse,
}) async {
  try {
    final raw = await fetch();
    await cache.write(key, raw);
    cache.markFresh();
    return parse(raw, stale: false);
  } on AppException catch (e) {
    final cached = cache.read(key);
    if (e.isTransient && cached != null && e is! RateLimitedException) {
      cache.markStale(cache.writtenAt(key));
      return parse(cached, stale: true);
    }
    rethrow;
  }
}
