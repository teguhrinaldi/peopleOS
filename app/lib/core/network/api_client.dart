import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:peopleos/core/config/app_config.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/network/error_mapper.dart';

/// Parsed `{success:true,data,meta}` envelope.
class ApiResponse {
  const ApiResponse(this.data, {this.meta = const {}, this.statusCode = 200});

  final Object? data;
  final Map<String, dynamic> meta;
  final int statusCode;

  Map<String, dynamic> get map => data is Map
      ? Map<String, dynamic>.from(data! as Map)
      : throw const UnknownException('Unexpected response shape.');

  List<dynamic> get list => data is List
      ? data! as List
      : throw const UnknownException('Unexpected response shape.');
}

/// Thin envelope-aware wrapper around Dio.
///
/// - base URL is resolved per request so the user can change the server
///   without rebuilding the client
/// - `Authorization: Bearer` is added when a token exists
/// - 401 (other than bad credentials) triggers [onUnauthorized]
/// - only idempotent GETs are retried (transient failures, small back-off);
///   writes are never blindly retried here - attendance writes use the outbox
class ApiClient {
  ApiClient({
    required this._dio,
    required this._readToken,
    required this._baseUrl,
    required this._onUnauthorized,
    this.localeTag,
    this.retryDelays = const [
      Duration(milliseconds: 400),
      Duration(milliseconds: 1200),
    ],
  });

  factory ApiClient.create({
    required Future<String?> Function() readToken,
    required String? Function() baseUrl,
    required void Function() onUnauthorized,
    String Function()? localeTag,
  }) {
    final dio = Dio(
      BaseOptions(
        connectTimeout: AppConfig.connectTimeout,
        receiveTimeout: AppConfig.receiveTimeout,
        sendTimeout: AppConfig.receiveTimeout,
        headers: {'Accept': 'application/json'},
        validateStatus: (s) => s != null && s >= 200 && s < 300,
      ),
    );
    return ApiClient(
      dio: dio,
      readToken: readToken,
      baseUrl: baseUrl,
      onUnauthorized: onUnauthorized,
      localeTag: localeTag,
    );
  }

  final Dio _dio;
  final Future<String?> Function() _readToken;
  final String? Function() _baseUrl;
  final void Function() _onUnauthorized;
  final List<Duration> retryDelays;

  /// Language the user chose; sent as `X-Locale` so server messages (and
  /// pre-login errors) come back in it where translated.
  final String Function()? localeTag;

  /// Normalises what a user may type: trims, drops trailing slashes and an
  /// accidentally pasted `/api/v1` suffix.
  static String? normalizeBaseUrl(String? raw) {
    var url = raw?.trim() ?? '';
    if (url.isEmpty) return null;
    if (!url.contains('://')) url = 'http://$url';
    while (url.endsWith('/')) {
      url = url.substring(0, url.length - 1);
    }
    if (url.endsWith(AppConfig.apiPrefix)) {
      url = url.substring(0, url.length - AppConfig.apiPrefix.length);
    }
    return url;
  }

  String _resolve(String path) {
    final base = normalizeBaseUrl(_baseUrl());
    if (base == null) {
      throw const UnknownException(
        'Server address is not configured.',
        'NO_SERVER_URL',
      );
    }
    if (path.startsWith('http')) return path;
    return '$base${AppConfig.apiPrefix}$path';
  }

  Future<Options> _options({Options? base}) async {
    final token = await _readToken();
    final headers = <String, dynamic>{...?base?.headers};
    if (token != null && token.isNotEmpty) {
      headers['Authorization'] = 'Bearer $token';
    }
    final tag = localeTag?.call();
    if (tag != null) headers['X-Locale'] = tag;
    return (base ?? Options()).copyWith(headers: headers);
  }

  Future<ApiResponse> get(String path, {Map<String, dynamic>? query}) {
    return _run(retry: true, () async {
      final res = await _dio.get<Object?>(
        _resolve(path),
        queryParameters: query,
        options: await _options(),
      );
      return _parse(res);
    });
  }

  /// [body] is JSON, or [FormData] for multipart (selfie) uploads.
  Future<ApiResponse> post(String path, {Object? body}) {
    return _run(retry: false, () async {
      final res = await _dio.post<Object?>(
        _resolve(path),
        data: body,
        options: await _options(),
      );
      return _parse(res);
    });
  }

  Future<ApiResponse> put(String path, {Object? body}) {
    return _run(retry: false, () async {
      final res = await _dio.put<Object?>(
        _resolve(path),
        data: body,
        options: await _options(),
      );
      return _parse(res);
    });
  }

  Future<ApiResponse> patch(String path, {Object? body}) {
    return _run(retry: false, () async {
      final res = await _dio.patch<Object?>(
        _resolve(path),
        data: body,
        options: await _options(),
      );
      return _parse(res);
    });
  }

  /// Follows pagination (`meta.last_page`) and concatenates `data` arrays.
  Future<List<dynamic>> getAll(
    String path, {
    Map<String, dynamic> query = const {},
    int perPage = 100,
    int maxPages = 20,
  }) async {
    final out = <dynamic>[];
    var page = 1;
    while (page <= maxPages) {
      final res = await get(
        path,
        query: {...query, 'page': page, 'per_page': perPage},
      );
      out.addAll(res.list);
      final last = res.meta['last_page'];
      if (last is! num || page >= last) break;
      page++;
    }
    return out;
  }

  Future<Uint8List> getBytes(String pathOrUrl) {
    return _run(retry: true, () async {
      final res = await _dio.get<List<int>>(
        _resolve(pathOrUrl),
        options: await _options(
          base: Options(responseType: ResponseType.bytes),
        ),
      );
      return Uint8List.fromList(res.data ?? const []);
    });
  }

  ApiResponse _parse(Response<Object?> res) {
    final body = res.data;
    if (body is! Map || body['success'] != true) {
      throw const UnknownException('Unexpected response from the server.');
    }
    final meta = body['meta'];
    return ApiResponse(
      body['data'],
      meta: meta is Map ? Map<String, dynamic>.from(meta) : const {},
      statusCode: res.statusCode ?? 200,
    );
  }

  Future<T> _run<T>(Future<T> Function() call, {required bool retry}) async {
    var attempt = 0;
    while (true) {
      try {
        return await call();
      } on AppException {
        rethrow;
      } on DioException catch (e) {
        final mapped = mapDioException(e);
        if (mapped is UnauthorizedException && !mapped.isBadCredentials) {
          _onUnauthorized();
        }
        final canRetry =
            retry &&
            attempt < retryDelays.length &&
            (mapped is NoConnectionException ||
                mapped is RequestTimeoutException ||
                mapped is ServerException);
        if (!canRetry) throw mapped;
        await Future<void>.delayed(retryDelays[attempt]);
        attempt++;
      }
    }
  }
}
