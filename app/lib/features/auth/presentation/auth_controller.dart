import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/l10n/locale_controller.dart';
import 'package:peopleos/features/auth/domain/session.dart';

enum AuthStatus { unknown, signedOut, signedIn }

/// Why the user landed on the login screen; localized there.
enum AuthNotice { sessionExpired }

@immutable
class AuthState {
  const AuthState({required this.status, this.session, this.notice});

  const AuthState.unknown() : this(status: AuthStatus.unknown);

  final AuthStatus status;
  final Session? session;

  /// Shown once on the login screen (e.g. "session expired").
  final AuthNotice? notice;

  bool get isSignedIn => status == AuthStatus.signedIn;
  Employee? get employee => session?.employee;
}

class AuthController extends Notifier<AuthState> {
  @override
  AuthState build() => const AuthState.unknown();

  /// Called once by the splash screen.
  Future<void> bootstrap() async {
    try {
      final session = await ref.read(authRepositoryProvider).restore();
      state = session == null
          ? const AuthState(status: AuthStatus.signedOut)
          : AuthState(status: AuthStatus.signedIn, session: session);
      if (session != null) {
        await ref
            .read(localeControllerProvider.notifier)
            .reconcileWithServer(session.serverLocale);
      }
    } on AppException {
      state = const AuthState(status: AuthStatus.signedOut);
    }
  }

  /// Throws [AppException]; the login screen renders it.
  Future<void> login({
    required String email,
    required String password,
    required bool remember,
  }) async {
    final session = await ref
        .read(authRepositoryProvider)
        .login(email: email.trim(), password: password);
    await ref
        .read(appSettingsProvider)
        .setRememberedEmail(remember ? email.trim() : null);
    state = AuthState(status: AuthStatus.signedIn, session: session);
    await ref
        .read(localeControllerProvider.notifier)
        .reconcileWithServer(session.serverLocale);
  }

  Future<void> refreshProfile() async {
    try {
      final session = await ref.read(authRepositoryProvider).refresh();
      state = AuthState(status: AuthStatus.signedIn, session: session);
    } on AppException {
      // Keep showing the cached profile.
    }
  }

  Future<void> logout() async {
    await ref.read(authRepositoryProvider).logout();
    await ref.read(jsonCacheProvider).clear();
    state = const AuthState(status: AuthStatus.signedOut);
  }

  /// A request came back 401: the token is no longer valid. Unsynced
  /// attendance stays in the outbox and is delivered after the next sign-in.
  Future<void> forceLogout() async {
    if (state.status != AuthStatus.signedIn) return;
    await ref.read(tokenStoreProvider).clear();
    state = const AuthState(
      status: AuthStatus.signedOut,
      notice: AuthNotice.sessionExpired,
    );
  }
}

final authControllerProvider = NotifierProvider<AuthController, AuthState>(
  AuthController.new,
);

/// Current employee id; only valid while signed in.
final employeeIdProvider = Provider<int?>(
  (ref) => ref.watch(authControllerProvider).employee?.id,
);
