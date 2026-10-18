import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/features/attendance/presentation/attendance_providers.dart';
import 'package:peopleos/features/auth/presentation/auth_controller.dart';
import 'package:peopleos/features/requests/domain/request_models.dart';
import 'package:peopleos/l10n/l10n.dart';

final correctionsProvider = FutureProvider<List<CorrectionRequest>>((ref) {
  ref.watch(employeeIdProvider);
  return ref.watch(requestsRepositoryProvider).corrections();
});

final overtimeProvider = FutureProvider<List<OvertimeRequest>>((ref) {
  ref.watch(employeeIdProvider);
  return ref.watch(requestsRepositoryProvider).overtime();
});

/// UTC offset of the company timezone, learned from the last server time.
/// Requests are built as company wall-clock time with this offset.
final companyOffsetProvider = Provider<Duration>((ref) {
  final today = ref.watch(serverTodayProvider).value?.today;
  return today?.serverTime.offset ?? DateTime.now().timeZoneOffset;
});

/// Friendly copy for request-related error codes (falls back to the server
/// message).
String describeRequestError(AppException e, AppLocalizations l10n) {
  switch (e.code) {
    case 'CORRECTION_ALREADY_PENDING':
      return l10n.requestsErrCorrectionAlreadyPending;
    case 'CORRECTION_WINDOW_EXPIRED':
      return l10n.requestsErrCorrectionWindowExpired;
    case 'INVALID_WORK_DATE':
      return l10n.requestsErrInvalidWorkDate;
    case 'CHECK_IN_REQUIRED':
      return l10n.requestsErrCheckInRequired;
    case 'OVERTIME_TOO_LONG':
      return l10n.requestsErrOvertimeTooLong;
    case 'OVERTIME_OVERLAP':
      return l10n.requestsErrOvertimeOverlap;
    case 'REQUEST_NOT_PENDING':
      return l10n.requestsErrNotPending;
    case 'INVALID_EVENT_TIME':
      return l10n.requestsErrInvalidEventTime;
  }
  return e.message;
}
