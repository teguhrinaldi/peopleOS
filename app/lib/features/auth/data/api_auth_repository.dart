import 'package:peopleos/core/config/app_config.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/network/api_client.dart';
import 'package:peopleos/core/storage/json_cache.dart';
import 'package:peopleos/core/storage/token_store.dart';
import 'package:peopleos/features/auth/domain/auth_repository.dart';
import 'package:peopleos/features/auth/domain/session.dart';

class ApiAuthRepository implements AuthRepository {
  ApiAuthRepository({
    required this._client,
    required this._tokens,
    required this._cache,
  });

  static const _sessionKey = 'session';

  final ApiClient _client;
  final TokenStore _tokens;
  final JsonCache _cache;

  @override
  Future<Session> login({
    required String email,
    required String password,
  }) async {
    final res = await _client.post(
      '/auth/login',
      body: {
        'email': email,
        'password': password,
        'device_name': AppConfig.deviceName,
      },
    );
    final data = res.map;
    final token = data['token'] as String?;
    if (token == null || token.isEmpty) {
      throw const UnknownException('The server did not return a token.');
    }
    final session = Session.fromJson(data);
    await _tokens.write(token);
    await _cache.write(_sessionKey, _withoutToken(data));
    return session;
  }

  @override
  Future<Session?> restore() async {
    final token = await _tokens.read();
    if (token == null || token.isEmpty) return null;
    try {
      return await refresh();
    } on UnauthorizedException {
      await _clearLocal();
      return null;
    } on AppException catch (e) {
      if (!e.isTransient) rethrow;
      final cached = _cache.read(_sessionKey);
      if (cached is Map) {
        _cache.markStale(_cache.writtenAt(_sessionKey));
        return Session.fromJson(Map<String, dynamic>.from(cached));
      }
      rethrow;
    }
  }

  @override
  Future<Session> refresh() async {
    final res = await _client.get('/auth/me');
    final data = res.map;
    await _cache.write(_sessionKey, _withoutToken(data));
    return Session.fromJson(data);
  }

  @override
  Future<bool> savePreferredLocale(String? locale) async {
    try {
      await _client.put('/auth/preferences', body: {'locale': locale});
      return true;
    } on AppException {
      return false;
    }
  }

  @override
  Future<void> logout() async {
    try {
      await _client.post('/auth/logout');
    } on AppException {
      // Offline or already expired: local credentials are still cleared.
    }
    await _clearLocal();
  }

  Future<void> _clearLocal() async {
    await _tokens.clear();
    await _cache.clear();
  }

  Map<String, dynamic> _withoutToken(Map<String, dynamic> data) => Map.of(data)
    ..remove('token')
    ..remove('token_type');
}
