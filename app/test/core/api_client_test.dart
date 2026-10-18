import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/l10n/generated/app_localizations_en.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/network/api_client.dart';
import 'package:peopleos/core/network/error_mapper.dart';

import '../helpers/sample_json.dart';

typedef Responder = FutureOr<ResponseBody> Function(RequestOptions options);

class _Adapter implements HttpClientAdapter {
  _Adapter(this.responder);
  final Responder responder;
  final List<RequestOptions> requests = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    return responder(options);
  }

  @override
  void close({bool force = false}) {}
}

ResponseBody _json(
  Object body,
  int status, {
  Map<String, List<String>>? headers,
}) => ResponseBody.fromString(
  jsonEncode(body),
  status,
  headers: {
    Headers.contentTypeHeader: [Headers.jsonContentType],
    ...?headers,
  },
);

void main() {
  late _Adapter adapter;
  var unauthorizedCalls = 0;
  String? token = 'tok';

  ApiClient build(Responder responder) {
    adapter = _Adapter(responder);
    final dio = Dio(BaseOptions(validateStatus: (s) => s != null && s < 300))
      ..httpClientAdapter = adapter;
    unauthorizedCalls = 0;
    return ApiClient(
      dio: dio,
      readToken: () async => token,
      baseUrl: () => 'http://api.test',
      onUnauthorized: () => unauthorizedCalls++,
      retryDelays: const [Duration.zero, Duration.zero],
    );
  }

  setUp(() => token = 'tok');

  group('envelope', () {
    test('parses data + meta and sends the bearer token', () async {
      final api = build(
        (o) => _json(okJson([1, 2], meta: {'page': 1, 'last_page': 1}), 200),
      );
      final res = await api.get('/things');

      expect(res.list, [1, 2]);
      expect(res.meta['last_page'], 1);
      expect(
        adapter.requests.single.uri.toString(),
        'http://api.test/api/v1/things',
      );
      expect(adapter.requests.single.headers['Authorization'], 'Bearer tok');
    });

    test('no Authorization header without a token', () async {
      token = null;
      final api = build((o) => _json(okJson({}), 200));
      await api.get('/x');
      expect(
        adapter.requests.single.headers.containsKey('Authorization'),
        isFalse,
      );
    });

    test('rejects a body that is not an API envelope', () async {
      final api = build((o) => _json({'hello': 'world'}, 200));
      await expectLater(api.get('/x'), throwsA(isA<UnknownException>()));
    });

    test('getAll follows meta.last_page', () async {
      final api = build((o) {
        final page = int.parse(o.queryParameters['page'].toString());
        return _json(
          okJson(
            [page * 10, page * 10 + 1],
            meta: {'page': page, 'last_page': 3},
          ),
          200,
        );
      });
      expect(await api.getAll('/list'), [10, 11, 20, 21, 30, 31]);
    });
  });

  group('error mapping by status and error.code', () {
    Future<Object> failWith(
      int status,
      Map<String, dynamic> body, {
      Map<String, List<String>>? headers,
    }) async {
      final api = build((o) => _json(body, status, headers: headers));
      try {
        await api.post('/x', body: {});
      } on AppException catch (e) {
        return e;
      }
      fail('expected an exception');
    }

    test('401 UNAUTHENTICATED forces logout', () async {
      final e = await failWith(401, errorJson('UNAUTHENTICATED', 'expired'));
      expect(e, isA<UnauthorizedException>());
      expect(unauthorizedCalls, 1);
    });

    test('401 INVALID_CREDENTIALS does not force logout', () async {
      final e = await failWith(401, errorJson('INVALID_CREDENTIALS', 'bad'));
      expect(e, isA<UnauthorizedException>());
      expect((e as UnauthorizedException).isBadCredentials, isTrue);
      expect(unauthorizedCalls, 0);
    });

    test('403 keeps the specific code', () async {
      final e = await failWith(403, errorJson('EMPLOYEE_TERMINATED', 'ended'));
      expect(e, isA<ForbiddenException>());
      expect((e as AppException).code, 'EMPLOYEE_TERMINATED');
    });

    test('404', () async {
      expect(
        await failWith(404, errorJson('NOT_FOUND', 'nope')),
        isA<NotFoundException>(),
      );
    });

    test('409 conflicts carry the code', () async {
      final e = await failWith(409, errorJson('ALREADY_CHECKED_IN', 'dup'));
      expect(e, isA<ConflictException>());
      expect((e as AppException).code, 'ALREADY_CHECKED_IN');
    });

    test('422 VALIDATION_ERROR exposes field errors', () async {
      final e = await failWith(
        422,
        errorJson('VALIDATION_ERROR', 'invalid', {
          'reason': ['The reason field is required.'],
          'work_date': ['Invalid date', 'Second message'],
        }),
      );
      final v = e as ValidationException;
      expect(v.fieldErrors.keys, containsAll(['reason', 'work_date']));
      expect(v.firstErrorFor('reason'), 'The reason field is required.');
      expect(v.firstErrorFor('work_date'), 'Invalid date');
      expect(v.firstErrorFor('nothing'), isNull);
    });

    test(
      '422 OUTSIDE_GEOFENCE keeps details and is not treated as field errors',
      () async {
        final e = await failWith(
          422,
          errorJson('OUTSIDE_GEOFENCE', 'far', {
            'distance_meters': 320.5,
            'radius_meters': 150,
          }),
        );
        final v = e as ValidationException;
        expect(v.code, 'OUTSIDE_GEOFENCE');
        expect(v.fieldErrors, isEmpty);
        expect(v.details['distance_meters'], 320.5);
        expect(
          describeAttendanceError(v, AppLocalizationsEn()),
          'You are 321 m from the work site. You must be within 150 m to record attendance.',
        );
      },
    );

    test('429 reads retry_after_seconds from details', () async {
      final e = await failWith(
        429,
        errorJson('TOO_MANY_ATTEMPTS', 'slow', {'retry_after_seconds': 42}),
      );
      expect(e, isA<RateLimitedException>());
      expect(
        (e as RateLimitedException).retryAfter,
        const Duration(seconds: 42),
      );
      expect(e.isTransient, isTrue);
    });

    test('429 falls back to the Retry-After header', () async {
      final e = await failWith(
        429,
        errorJson('TOO_MANY_REQUESTS', 'slow'),
        headers: {
          'retry-after': ['17'],
        },
      );
      expect(
        (e as RateLimitedException).retryAfter,
        const Duration(seconds: 17),
      );
    });

    test('5xx (even with an HTML body) is a ServerException', () async {
      final api = build(
        (o) => ResponseBody.fromString('<html>boom</html>', 503),
      );
      await expectLater(api.post('/x'), throwsA(isA<ServerException>()));
    });
  });

  group('transport failures', () {
    test('timeout', () {
      final e = mapDioException(
        DioException(
          requestOptions: RequestOptions(),
          type: DioExceptionType.receiveTimeout,
        ),
      );
      expect(e, isA<RequestTimeoutException>());
      expect(e.isTransient, isTrue);
    });

    test('no connection', () {
      final e = mapDioException(
        DioException(
          requestOptions: RequestOptions(),
          type: DioExceptionType.connectionError,
        ),
      );
      expect(e, isA<NoConnectionException>());
      expect(e.isTransient, isTrue);
    });
  });

  group('retry policy', () {
    test('idempotent GET retries transient failures then succeeds', () async {
      var n = 0;
      final api = build(
        (o) => ++n < 3
            ? _json(errorJson('SERVER_ERROR', 'x'), 503)
            : _json(okJson({'ok': true}), 200),
      );
      final res = await api.get('/x');
      expect(res.map['ok'], true);
      expect(adapter.requests, hasLength(3));
    });

    test('GET gives up after the configured retries', () async {
      final api = build((o) => _json(errorJson('SERVER_ERROR', 'x'), 500));
      await expectLater(api.get('/x'), throwsA(isA<ServerException>()));
      expect(adapter.requests, hasLength(3));
    });

    test('POST is never retried by the client', () async {
      final api = build((o) => _json(errorJson('SERVER_ERROR', 'x'), 500));
      await expectLater(
        api.post('/attendance/check-in'),
        throwsA(isA<ServerException>()),
      );
      expect(adapter.requests, hasLength(1));
    });

    test('GET does not retry business errors', () async {
      final api = build((o) => _json(errorJson('NOT_FOUND', 'x'), 404));
      await expectLater(api.get('/x'), throwsA(isA<NotFoundException>()));
      expect(adapter.requests, hasLength(1));
    });
  });

  test('normalizeBaseUrl trims, adds scheme and strips /api/v1', () {
    expect(
      ApiClient.normalizeBaseUrl(' http://10.0.2.2:8000/ '),
      'http://10.0.2.2:8000',
    );
    expect(
      ApiClient.normalizeBaseUrl('hr.example.com/api/v1/'),
      'http://hr.example.com',
    );
    expect(
      ApiClient.normalizeBaseUrl('https://hr.example.com'),
      'https://hr.example.com',
    );
    expect(ApiClient.normalizeBaseUrl('   '), isNull);
  });
}
