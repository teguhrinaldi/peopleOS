import 'package:dio/dio.dart';
import 'package:peopleos/core/error/app_exception.dart';

/// Translates transport failures and the API error envelope
/// (`{success:false,error:{code,message,details}}`) into typed exceptions.
/// Status decides the family; `error.code` refines it.
AppException mapDioException(DioException e) {
  switch (e.type) {
    case DioExceptionType.connectionTimeout:
    case DioExceptionType.sendTimeout:
    case DioExceptionType.receiveTimeout:
    case DioExceptionType.transformTimeout:
      return const RequestTimeoutException();
    case DioExceptionType.connectionError:
      return const NoConnectionException();
    case DioExceptionType.cancel:
      return const UnknownException('The request was cancelled.');
    case DioExceptionType.badCertificate:
      return const UnknownException(
        'The server certificate could not be verified.',
        'BAD_CERTIFICATE',
      );
    case DioExceptionType.badResponse:
      return mapErrorResponse(
        e.response?.statusCode ?? 0,
        e.response?.data,
        e.response?.headers,
      );
    case DioExceptionType.unknown:
      return const UnknownException();
  }
}

AppException mapErrorResponse(int status, Object? body, [Headers? headers]) {
  final error = body is Map ? body['error'] : null;
  final code = error is Map ? error['code'] as String? : null;
  final details = error is Map && error['details'] is Map
      ? Map<String, dynamic>.from(error['details'] as Map)
      : <String, dynamic>{};
  final message = (error is Map ? error['message'] as String? : null);

  switch (status) {
    case 401:
      return UnauthorizedException(
        message ?? 'Your session has expired. Please sign in again.',
        code: code ?? 'UNAUTHENTICATED',
      );
    case 403:
      return ForbiddenException(
        message ?? 'You are not allowed to do this.',
        code: code ?? 'FORBIDDEN',
      );
    case 404:
      return NotFoundException(
        message ?? 'This item could not be found.',
        code: code ?? 'NOT_FOUND',
      );
    case 409:
      return ConflictException(
        message ?? 'This conflicts with the current state.',
        code: code,
      );
    case 422:
      return ValidationException(
        message ?? 'Please check the highlighted fields.',
        code: code ?? 'VALIDATION_ERROR',
        fieldErrors: code == 'VALIDATION_ERROR' || code == null
            ? _fieldErrors(details)
            : const {},
        details: details,
      );
    case 429:
      final seconds =
          _asInt(details['retry_after_seconds']) ??
          _asInt(headers?.value('retry-after')) ??
          30;
      return RateLimitedException(
        message ?? 'Too many requests. Please wait a moment.',
        code: code ?? 'TOO_MANY_REQUESTS',
        retryAfter: Duration(seconds: seconds),
      );
  }
  if (status >= 500) {
    return ServerException(
      'Something went wrong on the server. Please try again.',
      code,
    );
  }
  return UnknownException(message ?? 'Unexpected error ($status).', code);
}

Map<String, List<String>> _fieldErrors(Map<String, dynamic> details) {
  final out = <String, List<String>>{};
  details.forEach((field, value) {
    if (value is List) {
      out[field] = value.map((e) => e.toString()).toList();
    } else if (value is String) {
      out[field] = [value];
    }
  });
  return out;
}

int? _asInt(Object? v) {
  if (v is int) return v;
  if (v is num) return v.toInt();
  if (v is String) return int.tryParse(v);
  return null;
}
