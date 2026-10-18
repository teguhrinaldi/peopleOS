import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/features/auth/presentation/auth_controller.dart';

import '../helpers/fakes.dart';
import '../helpers/harness.dart';

Future<void> fillAndSubmit(
  WidgetTester tester,
  String email,
  String password,
) async {
  final fields = find.byType(TextFormField);
  await tester.enterText(fields.at(0), email);
  await tester.enterText(fields.at(1), password);
  await tester.tap(find.text('Sign in'));
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 300));
}

void main() {
  testWidgets('shows the login screen when there is no session', (
    tester,
  ) async {
    final env = TestEnv(auth: FakeAuthRepository(signedIn: false));
    await pumpApp(tester, env);

    expect(find.text('Welcome back'), findsOneWidget);
    expect(find.text('Sign in to your account'), findsOneWidget);
    expect(find.text('Demo Account'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('empty form shows inline validation and does not call the API', (
    tester,
  ) async {
    final env = TestEnv(auth: FakeAuthRepository(signedIn: false));
    await pumpApp(tester, env);

    await tester.tap(find.text('Sign in'));
    await tester.pump();

    expect(find.text('Enter your email address'), findsOneWidget);
    expect(find.text('Password is required'), findsOneWidget);
    expect(env.auth.loginCalls, 0);
    await unmount(tester);
  });

  testWidgets('malformed email is rejected locally', (tester) async {
    final env = TestEnv(auth: FakeAuthRepository(signedIn: false));
    await pumpApp(tester, env);

    await fillAndSubmit(tester, 'not-an-email', 'password');

    expect(find.text('Enter a valid email address'), findsOneWidget);
    expect(env.auth.loginCalls, 0);
    await unmount(tester);
  });

  testWidgets('wrong password shows an error banner and stays on login', (
    tester,
  ) async {
    final auth = FakeAuthRepository(signedIn: false)
      ..loginError = const UnauthorizedException(
        'Invalid credentials',
        code: 'INVALID_CREDENTIALS',
      );
    final env = TestEnv(auth: auth);
    await pumpApp(tester, env);

    await fillAndSubmit(tester, 'employee@peopleos.test', 'wrong');

    expect(find.text('The email or password is incorrect.'), findsOneWidget);
    expect(find.text('Welcome back'), findsOneWidget);
    expect(auth.loginCalls, 1);
    await unmount(tester);
  });

  testWidgets('successful login lands on Home', (tester) async {
    final env = TestEnv(auth: FakeAuthRepository(signedIn: false));
    await pumpApp(tester, env);

    await fillAndSubmit(tester, ' employee@peopleos.test ', 'password');
    await settle(tester);

    expect(
      env.auth.lastEmail,
      'employee@peopleos.test',
      reason: 'email is trimmed',
    );
    expect(find.text('Michael Carter'), findsOneWidget);
    expect(find.text("Today's Shift"), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('429 tells the user to wait and locks the button', (
    tester,
  ) async {
    final auth = FakeAuthRepository(signedIn: false)
      ..loginError = const RateLimitedException(
        'Too many attempts',
        code: 'TOO_MANY_ATTEMPTS',
        retryAfter: Duration(seconds: 40),
      );
    final env = TestEnv(auth: auth);
    await pumpApp(tester, env);

    await fillAndSubmit(tester, 'employee@peopleos.test', 'x');

    expect(find.textContaining('Try again in 4'), findsOneWidget);
    // Tapping again while locked does nothing.
    await tester.tap(find.text('Sign in'));
    await tester.pump();
    expect(auth.loginCalls, 1);
    await unmount(tester);
  });

  testWidgets('network failure is reported on the login screen', (
    tester,
  ) async {
    final auth = FakeAuthRepository(signedIn: false)
      ..loginError = const NoConnectionException();
    final env = TestEnv(auth: auth);
    await pumpApp(tester, env);

    await fillAndSubmit(tester, 'employee@peopleos.test', 'password');

    expect(find.textContaining('No internet connection'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('password visibility toggle', (tester) async {
    final env = TestEnv(auth: FakeAuthRepository(signedIn: false));
    await pumpApp(tester, env);

    TextField password() => tester.widget<TextField>(
      find.descendant(
        of: find.byType(TextFormField).at(1),
        matching: find.byType(TextField),
      ),
    );
    expect(password().obscureText, isTrue);
    await tester.tap(find.byTooltip('Show password'));
    await tester.pump();
    expect(password().obscureText, isFalse);
    await unmount(tester);
  });

  testWidgets('a 401 anywhere forces logout with a session-expired notice', (
    tester,
  ) async {
    final env = TestEnv();
    await pumpApp(tester, env);
    expect(find.text('Michael Carter'), findsOneWidget);

    final container = ProviderScope.containerOf(
      tester.element(find.byType(MaterialApp)),
    );
    await container.read(authControllerProvider.notifier).forceLogout();
    await settle(tester);

    expect(find.text('Welcome back'), findsOneWidget);
    expect(
      find.text('Your session has expired. Please sign in again.'),
      findsOneWidget,
    );
    await unmount(tester);
  });
}
