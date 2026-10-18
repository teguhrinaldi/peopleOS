import 'package:peopleos/features/auth/domain/auth_repository.dart';
import 'package:peopleos/features/auth/domain/session.dart';
import 'package:peopleos/features/demo/demo_backend.dart';

class DemoAuthRepository implements AuthRepository {
  DemoAuthRepository(this._backend);

  final DemoBackend _backend;

  @override
  Future<Session> login({
    required String email,
    required String password,
  }) async => Session.fromJson(await _backend.login(email, password));

  @override
  Future<Session?> restore() async =>
      _backend.signedIn ? Session.fromJson(_backend.session()) : null;

  @override
  Future<Session> refresh() async => Session.fromJson(_backend.session());

  @override
  Future<bool> savePreferredLocale(String? locale) async => true;

  @override
  Future<void> logout() => _backend.logout();
}
