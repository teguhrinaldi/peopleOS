import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/network/api_client.dart';
import 'package:peopleos/core/storage/json_cache.dart';
import 'package:peopleos/core/storage/token_store.dart';
import 'package:peopleos/features/attendance/data/api_attendance_repository.dart';
import 'package:peopleos/features/attendance/data/demo_attendance_repository.dart';
import 'package:peopleos/features/auth/data/api_auth_repository.dart';
import 'package:peopleos/features/auth/data/demo_auth_repository.dart';
import 'package:peopleos/features/documents/data/api_documents_repository.dart';
import 'package:peopleos/features/documents/data/demo_documents_repository.dart';
import 'package:peopleos/features/leave/data/api_leave_repository.dart';
import 'package:peopleos/features/leave/data/demo_leave_repository.dart';
import 'package:peopleos/features/notifications/data/api_notifications_repository.dart';
import 'package:peopleos/features/notifications/data/demo_notifications_repository.dart';
import 'package:peopleos/features/payslip/data/api_payslip_repository.dart';
import 'package:peopleos/features/payslip/data/demo_payslip_repository.dart';
import 'package:peopleos/features/requests/data/api_requests_repository.dart';
import 'package:peopleos/features/requests/data/demo_requests_repository.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../helpers/harness.dart';

Future<ProviderContainer> container({required bool api}) async {
  SharedPreferences.setMockInitialValues({});
  final prefs = await SharedPreferences.getInstance();
  final c = ProviderContainer(
    overrides: [
      apiModeProvider.overrideWithValue(api),
      sharedPreferencesProvider.overrideWithValue(prefs),
      tokenStoreProvider.overrideWithValue(MemoryTokenStore()),
    ],
  );
  addTearDown(c.dispose);
  return c;
}

/// Behaves like a server that is down: every request is refused.
class _DeadServer implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async => throw DioException(
    requestOptions: options,
    type: DioExceptionType.connectionError,
  );

  @override
  void close({bool force = false}) {}
}

void main() {
  group('DI picks implementations by mode', () {
    test('API mode resolves ONLY API repositories', () async {
      final c = await container(api: true);
      expect(c.read(authRepositoryProvider), isA<ApiAuthRepository>());
      expect(
        c.read(attendanceRepositoryProvider),
        isA<ApiAttendanceRepository>(),
      );
      expect(c.read(requestsRepositoryProvider), isA<ApiRequestsRepository>());
      expect(c.read(leaveRepositoryProvider), isA<ApiLeaveRepository>());
      expect(c.read(payslipRepositoryProvider), isA<ApiPayslipRepository>());
      expect(
        c.read(documentsRepositoryProvider),
        isA<ApiDocumentsRepository>(),
      );
      expect(
        c.read(notificationsRepositoryProvider),
        isA<ApiNotificationsRepository>(),
      );
      // Nothing demo may be among them.
      for (final repo in [
        c.read(authRepositoryProvider),
        c.read(attendanceRepositoryProvider),
        c.read(requestsRepositoryProvider),
        c.read(leaveRepositoryProvider),
        c.read(payslipRepositoryProvider),
        c.read(documentsRepositoryProvider),
        c.read(notificationsRepositoryProvider),
      ]) {
        expect(repo.runtimeType.toString(), isNot(startsWith('Demo')));
      }
    });

    test('Demo mode resolves ONLY demo repositories', () async {
      final c = await container(api: false);
      expect(c.read(authRepositoryProvider), isA<DemoAuthRepository>());
      expect(
        c.read(attendanceRepositoryProvider),
        isA<DemoAttendanceRepository>(),
      );
      expect(c.read(requestsRepositoryProvider), isA<DemoRequestsRepository>());
      expect(c.read(leaveRepositoryProvider), isA<DemoLeaveRepository>());
      expect(c.read(payslipRepositoryProvider), isA<DemoPayslipRepository>());
      expect(
        c.read(documentsRepositoryProvider),
        isA<DemoDocumentsRepository>(),
      );
      expect(
        c.read(notificationsRepositoryProvider),
        isA<DemoNotificationsRepository>(),
      );
    });

    test(
      'the build default is Demo mode (flutter run needs no backend)',
      () async {
        SharedPreferences.setMockInitialValues({});
        final prefs = await SharedPreferences.getInstance();
        final c = ProviderContainer(
          overrides: [sharedPreferencesProvider.overrideWithValue(prefs)],
        );
        addTearDown(c.dispose);
        expect(c.read(apiModeProvider), isFalse);
      },
    );
  });

  group('static scan: demo code is unreachable from API code', () {
    List<File> dart(String dir) =>
        Directory(dir)
            .listSync(recursive: true)
            .whereType<File>()
            .where((f) => f.path.endsWith('.dart'))
            .toList();

    test('api_* data sources never import demo code', () {
      final offenders = [
        for (final f in dart('lib'))
          if (f.uri.pathSegments.last.startsWith('api_') &&
              RegExp(r'''import\s+['"][^'"]*(demo_|/demo/)''')
                  .hasMatch(f.readAsStringSync()))
            f.path,
      ];
      expect(offenders, isEmpty);
    });

    test(
      'only DI, the demo feature and demo-only screens reference demo code',
      () {
        const allowed = {
          'lib/app/di.dart',
          'lib/features/auth/presentation/login_screen.dart', // "Demo Account" button (demo mode only)
          'lib/features/profile/presentation/settings_screen.dart', // demo controls panel (demo mode only)
        };
        final offenders = <String>[];
        for (final f in dart('lib')) {
          final path = f.path.replaceAll('\\', '/');
          final name = f.uri.pathSegments.last;
          if (path.contains('/features/demo/') || name.startsWith('demo_')) {
            continue;
          }
          if (allowed.any(path.endsWith)) continue;
          if (RegExp(r'''import\s+['"][^'"]*(demo_|/demo/)''')
              .hasMatch(f.readAsStringSync())) {
            offenders.add(path);
          }
        }
        expect(offenders, isEmpty);
      },
    );
  });

  group('a dead server shows an error, never data', () {
    ApiClient deadClient() => ApiClient(
      dio: Dio()..httpClientAdapter = _DeadServer(),
      readToken: () async => 't',
      baseUrl: () => 'http://127.0.0.1:1',
      onUnauthorized: () {},
      retryDelays: const [],
    );

    test(
      'API repository with no cache throws NoConnection / timeout',
      () async {
        SharedPreferences.setMockInitialValues({});
        final cache = JsonCache(await SharedPreferences.getInstance());
        final repo = ApiAttendanceRepository(
          client: deadClient(),
          cache: cache,
        );
        await expectLater(
          repo.today(),
          throwsA(
            anyOf(isA<NoConnectionException>(), isA<RequestTimeoutException>()),
          ),
        );
        expect(cache.staleSince.value, isNull, reason: 'nothing stale to show');
      },
    );

    test(
      'cached data is marked stale with its age, and cleared by a live read',
      () async {
        SharedPreferences.setMockInitialValues({});
        final cache = JsonCache(await SharedPreferences.getInstance());
        await cache.write('leave.types', []);
        final repo = ApiLeaveRepository(client: deadClient(), cache: cache);
        expect(await repo.types(), isEmpty);
        expect(cache.staleSince.value, isNotNull);
        expect(
          cache.staleSince.value!.isBefore(
            DateTime.now().toUtc().add(const Duration(seconds: 1)),
          ),
          isTrue,
        );
        cache.markFresh();
        expect(cache.staleSince.value, isNull);
      },
    );

    test('a 4xx business error is never replaced by cached data', () async {
      SharedPreferences.setMockInitialValues({});
      final cache = JsonCache(await SharedPreferences.getInstance());
      await cache.write('documents.types', []);
      // 127.0.0.1 with an unconfigured URL yields a non-transient error.
      final client = ApiClient.create(
        readToken: () async => 't',
        baseUrl: () => null,
        onUnauthorized: () {},
      );
      final repo = ApiDocumentsRepository(client: client, cache: cache);
      await expectLater(repo.types(), throwsA(isA<UnknownException>()));
    });

    testWidgets(
      'Home, history and lists show an error state when the API is down',
      (tester) async {
        SharedPreferences.setMockInitialValues({});
        final cache = JsonCache(await SharedPreferences.getInstance());
        // The real API repository against a dead server and an empty cache.
        final env = TestEnv(
          attendanceOverride: ApiAttendanceRepository(
            client: deadClient(),
            cache: cache,
          ),
        );
        await pumpApp(tester, env);
        await tester.pump(const Duration(seconds: 25));
        await tester.pump();
        expect(find.text('Try again'), findsOneWidget);
        expect(
          find.text('Check In'),
          findsNothing,
          reason: 'no data may be invented',
        );
        expect(find.text("Today's Shift"), findsNothing);
        await unmount(tester);
      },
      timeout: const Timeout(Duration(minutes: 2)),
    );
  });
}
