import 'package:peopleos/features/auth/domain/session.dart';

abstract class AuthRepository {
  /// Throws [UnauthorizedException] (bad credentials), [ValidationException],
  /// [RateLimitedException], [ForbiddenException] (account/employee state) or
  /// a transport error.
  Future<Session> login({required String email, required String password});

  /// Restores a previous session from secure storage. Returns null when there
  /// is none or it expired. If the network is down the last known session is
  /// returned so the user can keep recording attendance offline.
  Future<Session?> restore();

  /// Refreshes the profile (`GET /auth/me`).
  Future<Session> refresh();

  /// Saves the language choice on the server user (`PUT /auth/preferences`).
  /// Returns false when the server could not store it (offline, endpoint
  /// missing); the local choice still applies.
  Future<bool> savePreferredLocale(String? locale);

  /// Best-effort server logout; always clears local credentials.
  Future<void> logout();
}
