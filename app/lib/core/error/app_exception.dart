import 'package:peopleos/l10n/generated/app_localizations.dart';

/// Typed failures surfaced by every repository. The UI branches on the
/// subtype (or [code]) and never on the human-readable [message].
sealed class AppException implements Exception {
  const AppException(this.message, {this.code});

  final String message;
  final String? code;

  /// True when retrying the very same request later may succeed
  /// (connectivity, timeout, server fault, rate limit).
  bool get isTransient => false;

  @override
  String toString() => '$runtimeType($code): $message';
}

class NoConnectionException extends AppException {
  const NoConnectionException([
    super.message = 'No internet connection. Check your network and try again.',
  ]) : super(code: 'NO_CONNECTION');

  @override
  bool get isTransient => true;
}

class RequestTimeoutException extends AppException {
  const RequestTimeoutException([
    super.message = 'The server took too long to respond.',
  ]) : super(code: 'TIMEOUT');

  @override
  bool get isTransient => true;
}

/// 401 - token missing/expired. `INVALID_CREDENTIALS` is a login failure and
/// must not force a logout.
class UnauthorizedException extends AppException {
  const UnauthorizedException(super.message, {super.code});

  bool get isBadCredentials => code == 'INVALID_CREDENTIALS';
}

class ForbiddenException extends AppException {
  const ForbiddenException(super.message, {super.code});
}

class NotFoundException extends AppException {
  const NotFoundException(super.message, {super.code});
}

class ConflictException extends AppException {
  const ConflictException(super.message, {super.code});
}

/// 422 - either field validation (with [fieldErrors]) or a business rule such
/// as `OUTSIDE_GEOFENCE` (see [details]).
class ValidationException extends AppException {
  const ValidationException(
    super.message, {
    super.code,
    this.fieldErrors = const {},
    this.details = const {},
  });

  final Map<String, List<String>> fieldErrors;
  final Map<String, dynamic> details;

  String? firstErrorFor(String field) {
    final list = fieldErrors[field];
    return (list == null || list.isEmpty) ? null : list.first;
  }
}

class RateLimitedException extends AppException {
  const RateLimitedException(
    super.message, {
    super.code,
    this.retryAfter = const Duration(seconds: 30),
  });

  final Duration retryAfter;

  @override
  bool get isTransient => true;
}

class ServerException extends AppException {
  const ServerException([
    super.message = 'Something went wrong on the server. Please try again.',
    String? code,
  ]) : super(code: code ?? 'SERVER_ERROR');

  @override
  bool get isTransient => true;
}

/// Anything else (unexpected shape, unmapped 4xx).
class UnknownException extends AppException {
  const UnknownException([
    super.message = 'Unexpected error. Please try again.',
    String? code,
  ]) : super(code: code ?? 'UNKNOWN');
}

/// Human friendly, localized copy for attendance business codes. Falls back
/// to the server message so new backend codes still show something useful.
String describeAttendanceError(AppException e, AppLocalizations l10n) {
  final details = e is ValidationException ? e.details : const {};
  switch (e.code) {
    case 'OUTSIDE_GEOFENCE':
      final d = details['distance_meters'];
      final r = details['radius_meters'];
      if (d is num && r is num) {
        return l10n.errAttOutsideGeofenceDistance(d.round(), r.round());
      }
      return l10n.errAttOutsideGeofence;
    case 'GPS_REQUIRED':
      return l10n.errAttGpsRequired;
    case 'GPS_ACCURACY_TOO_LOW':
      return l10n.errAttGpsAccuracyTooLow;
    case 'MOCK_LOCATION_DETECTED':
      return l10n.errAttMockLocation;
    case 'LOCATION_STALE':
      return l10n.errAttLocationStale;
    case 'SELFIE_REQUIRED':
      return l10n.errAttSelfieRequired;
    case 'ALREADY_CHECKED_IN':
      return l10n.errAttAlreadyCheckedIn;
    case 'ALREADY_CHECKED_OUT':
      return l10n.errAttAlreadyCheckedOut;
    case 'NOT_CHECKED_IN':
      return l10n.errAttNotCheckedIn;
    case 'CHECK_OUT_TOO_LATE':
      return l10n.errAttCheckOutTooLate;
    case 'ON_LEAVE':
      return l10n.errAttOnLeave;
    case 'NO_SCHEDULE':
      return l10n.errAttNoSchedule;
    case 'NOT_A_WORKING_DAY':
      return l10n.errAttNotWorkingDay;
    case 'OFFLINE_EVENT_TOO_OLD':
      return l10n.errAttOfflineEventTooOld;
    case 'INVALID_EVENT_TIME':
      return l10n.errAttInvalidEventTime;
    case 'EMPLOYEE_TERMINATED':
      return l10n.errAttEmployeeTerminated;
    case 'EMPLOYEE_NOT_ACTIVE':
      return l10n.errAttEmployeeNotActive;
    case 'ACCOUNT_DISABLED':
      return l10n.errAttAccountDisabled;
    case 'IDEMPOTENCY_KEY_CONFLICT':
      return l10n.errAttIdempotencyConflict;
  }
  return e.message;
}

/// Localized message for any failure. Known exception families use app copy;
/// the server message is only shown for unknown / validation errors.
String localizedErrorMessage(Object error, AppLocalizations l10n) {
  return switch (error) {
    NoConnectionException() => l10n.errNoConnection,
    RequestTimeoutException() => l10n.errTimeout,
    ForbiddenException() => l10n.errForbidden,
    NotFoundException() => l10n.errNotFound,
    ServerException() => l10n.errServer,
    RateLimitedException() => l10n.errRateLimited,
    UnauthorizedException(isBadCredentials: false) => l10n.errUnauthorized,
    AppException e => e.message,
    _ => l10n.errPleaseTryAgain,
  };
}
