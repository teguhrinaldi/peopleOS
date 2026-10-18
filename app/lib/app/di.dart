import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/core/config/app_config.dart';
import 'package:peopleos/core/l10n/locale_controller.dart';
import 'package:peopleos/core/network/api_client.dart';
import 'package:peopleos/core/network/connectivity_service.dart';
import 'package:peopleos/core/platform/file_exporter.dart';
import 'package:peopleos/core/platform/location_service.dart';
import 'package:peopleos/core/platform/selfie_service.dart';
import 'package:peopleos/core/storage/app_settings.dart';
import 'package:peopleos/core/storage/json_cache.dart';
import 'package:peopleos/core/storage/token_store.dart';
import 'package:peopleos/features/attendance/application/attendance_sync_service.dart';
import 'package:peopleos/features/attendance/data/api_attendance_repository.dart';
import 'package:peopleos/features/attendance/data/demo_attendance_repository.dart';
import 'package:peopleos/features/attendance/data/outbox_store.dart';
import 'package:peopleos/features/attendance/data/selfie_store.dart';
import 'package:peopleos/features/attendance/domain/attendance_repository.dart';
import 'package:peopleos/features/auth/data/api_auth_repository.dart';
import 'package:peopleos/features/auth/data/demo_auth_repository.dart';
import 'package:peopleos/features/auth/domain/auth_repository.dart';
import 'package:peopleos/features/auth/presentation/auth_controller.dart';
import 'package:peopleos/features/demo/demo_backend.dart';
import 'package:peopleos/features/demo/demo_services.dart';
import 'package:peopleos/features/leave/data/api_leave_repository.dart';
import 'package:peopleos/features/leave/data/demo_leave_repository.dart';
import 'package:peopleos/features/leave/domain/leave_repository.dart';
import 'package:peopleos/features/notifications/data/api_notifications_repository.dart';
import 'package:peopleos/features/notifications/data/demo_notifications_repository.dart';
import 'package:peopleos/features/notifications/domain/app_notification.dart';
import 'package:peopleos/features/documents/data/api_documents_repository.dart';
import 'package:peopleos/features/documents/data/demo_documents_repository.dart';
import 'package:peopleos/features/documents/domain/document.dart';
import 'package:peopleos/features/payslip/data/api_payslip_repository.dart';
import 'package:peopleos/features/payslip/data/demo_payslip_repository.dart';
import 'package:peopleos/features/payslip/domain/payslip.dart';
import 'package:peopleos/features/requests/data/api_requests_repository.dart';
import 'package:peopleos/features/requests/data/demo_requests_repository.dart';
import 'package:peopleos/features/requests/domain/requests_repository.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Dependency wiring. `AppConfig.useApi` selects the implementations once,
/// here; nothing above the repositories knows which mode is running.
/// Tests replace any provider via `ProviderScope(overrides: ...)`.

/// true = Laravel API mode, false = Demo mode. Defaults to the build flag;
/// tests override it to prove which implementations each mode resolves.
final apiModeProvider = Provider<bool>((ref) => AppConfig.useApi);

final sharedPreferencesProvider = Provider<SharedPreferences>(
  (ref) => throw UnimplementedError('Override sharedPreferencesProvider'),
);

final appSettingsProvider = Provider<AppSettings>(
  (ref) => AppSettings(ref.watch(sharedPreferencesProvider)),
);

final jsonCacheProvider = Provider<JsonCache>(
  (ref) => JsonCache(ref.watch(sharedPreferencesProvider)),
);

final tokenStoreProvider = Provider<TokenStore>((ref) => SecureTokenStore());

// ---- platform services ------------------------------------------------------

final realConnectivityProvider = Provider<ConnectivityService>(
  (ref) => PluginConnectivityService(),
);

final demoBackendProvider = Provider<DemoBackend>(
  (ref) => DemoBackend(
    prefs: ref.watch(sharedPreferencesProvider),
    settings: ref.watch(appSettingsProvider),
  ),
);

final connectivityServiceProvider = Provider<ConnectivityService>((ref) {
  final real = ref.watch(realConnectivityProvider);
  if (ref.watch(apiModeProvider)) return real;
  return DemoConnectivityService(real, ref.watch(appSettingsProvider));
});

final locationServiceProvider = Provider<LocationService>((ref) {
  if (ref.watch(apiModeProvider)) return const GeolocatorLocationService();
  return DemoLocationService(ref.watch(appSettingsProvider));
});

final selfieServiceProvider = Provider<SelfieService>((ref) {
  final camera = ImagePickerSelfieService();
  return ref.watch(apiModeProvider) ? camera : DemoSelfieService(camera);
});

/// Live online/offline flag (starts optimistic, then follows the stream).
class OnlineNotifier extends Notifier<bool> {
  @override
  bool build() {
    final service = ref.watch(connectivityServiceProvider);
    final sub = service.onlineChanges.listen((v) => state = v);
    ref.onDispose(sub.cancel);
    service.isOnline.then((v) => state = v);
    return true;
  }
}

final isOnlineProvider = NotifierProvider<OnlineNotifier, bool>(
  OnlineNotifier.new,
);

// ---- server URL / API client -------------------------------------------------

class ServerUrlNotifier extends Notifier<String?> {
  @override
  String? build() {
    final saved = ref.watch(appSettingsProvider).serverUrl;
    if (saved != null && saved.isNotEmpty) return saved;
    return AppConfig.defaultApiBaseUrl.isEmpty
        ? null
        : AppConfig.defaultApiBaseUrl;
  }

  Future<void> save(String raw) async {
    final url = ApiClient.normalizeBaseUrl(raw);
    if (url == null) return;
    await ref.read(appSettingsProvider).setServerUrl(url);
    state = url;
  }
}

final serverUrlProvider = NotifierProvider<ServerUrlNotifier, String?>(
  ServerUrlNotifier.new,
);

final apiClientProvider = Provider<ApiClient>((ref) {
  final tokens = ref.watch(tokenStoreProvider);
  return ApiClient.create(
    readToken: tokens.read,
    baseUrl: () => ref.read(serverUrlProvider),
    onUnauthorized: () =>
        ref.read(authControllerProvider.notifier).forceLogout(),
    localeTag: () => ref.read(effectiveLocaleProvider).languageCode,
  );
});

// ---- repositories ---------------------------------------------------------

final authRepositoryProvider = Provider<AuthRepository>((ref) {
  if (!ref.watch(apiModeProvider)) {
    return DemoAuthRepository(ref.watch(demoBackendProvider));
  }
  return ApiAuthRepository(
    client: ref.watch(apiClientProvider),
    tokens: ref.watch(tokenStoreProvider),
    cache: ref.watch(jsonCacheProvider),
  );
});

final appVersionProvider = Provider<String>((ref) => '1.0.0');

final attendanceRepositoryProvider = Provider<AttendanceRepository>((ref) {
  if (!ref.watch(apiModeProvider)) {
    return DemoAttendanceRepository(ref.watch(demoBackendProvider));
  }
  return ApiAttendanceRepository(
    client: ref.watch(apiClientProvider),
    cache: ref.watch(jsonCacheProvider),
    appVersion: ref.watch(appVersionProvider),
  );
});

final requestsRepositoryProvider = Provider<RequestsRepository>((ref) {
  if (!ref.watch(apiModeProvider)) {
    return DemoRequestsRepository(ref.watch(demoBackendProvider));
  }
  return ApiRequestsRepository(
    client: ref.watch(apiClientProvider),
    cache: ref.watch(jsonCacheProvider),
  );
});

final payslipRepositoryProvider = Provider<PayslipRepository>((ref) {
  if (!ref.watch(apiModeProvider)) {
    return DemoPayslipRepository(ref.watch(demoBackendProvider));
  }
  return ApiPayslipRepository(
    client: ref.watch(apiClientProvider),
    cache: ref.watch(jsonCacheProvider),
  );
});

final documentsRepositoryProvider = Provider<DocumentsRepository>((ref) {
  if (!ref.watch(apiModeProvider)) {
    return DemoDocumentsRepository(ref.watch(demoBackendProvider));
  }
  return ApiDocumentsRepository(
    client: ref.watch(apiClientProvider),
    cache: ref.watch(jsonCacheProvider),
  );
});

final fileExporterProvider = Provider<FileExporter>(
  (ref) => createFileExporter(),
);

final notificationsRepositoryProvider = Provider<NotificationsRepository>((
  ref,
) {
  if (!ref.watch(apiModeProvider)) {
    return DemoNotificationsRepository(ref.watch(demoBackendProvider));
  }
  return ApiNotificationsRepository(ref.watch(apiClientProvider));
});

final leaveRepositoryProvider = Provider<LeaveRepository>((ref) {
  if (!ref.watch(apiModeProvider)) {
    return DemoLeaveRepository(ref.watch(demoBackendProvider));
  }
  return ApiLeaveRepository(
    client: ref.watch(apiClientProvider),
    cache: ref.watch(jsonCacheProvider),
  );
});

// ---- offline outbox ---------------------------------------------------------

final outboxStoreProvider = Provider<OutboxStore>(
  (ref) => PrefsOutboxStore(ref.watch(sharedPreferencesProvider)),
);

final selfieStoreProvider = Provider<SelfieStore>(
  (ref) => kIsWeb
      ? PrefsSelfieStore(ref.watch(sharedPreferencesProvider))
      : FileSelfieStore(),
);

final attendanceSyncServiceProvider = Provider<AttendanceSyncService>(
  (ref) => AttendanceSyncService(
    repository: ref.watch(attendanceRepositoryProvider),
    store: ref.watch(outboxStoreProvider),
    selfies: ref.watch(selfieStoreProvider),
    connectivity: ref.watch(connectivityServiceProvider),
  ),
);

// ---- theme ------------------------------------------------------------------

class ThemeModeNotifier extends Notifier<ThemeMode> {
  @override
  ThemeMode build() => ref.watch(appSettingsProvider).themeMode;

  Future<void> set(ThemeMode mode) async {
    state = mode;
    await ref.read(appSettingsProvider).setThemeMode(mode);
  }
}

final themeModeProvider = NotifierProvider<ThemeModeNotifier, ThemeMode>(
  ThemeModeNotifier.new,
);
