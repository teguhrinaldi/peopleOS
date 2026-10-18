/// Compile-time configuration.
///
/// Demo mode (default) needs no backend:
///   flutter run
/// API mode talks to the PeopleOS Laravel API:
///   flutter run --dart-define=PEOPLEOS_USE_API=true --dart-define=API_BASE_URL=http://10.0.2.2:8000
class AppConfig {
  const AppConfig._();

  static const bool useApi = bool.fromEnvironment('PEOPLEOS_USE_API');

  /// Optional build-time server URL. When empty in API mode the login screen
  /// asks for it and persists the answer.
  static const String defaultApiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
  );

  static const String apiPrefix = '/api/v1';

  static const Duration connectTimeout = Duration(seconds: 15);
  static const Duration receiveTimeout = Duration(seconds: 30);

  /// Name reported to `POST /auth/login` as `device_name`.
  static const String deviceName = 'PeopleOS mobile app';
}
