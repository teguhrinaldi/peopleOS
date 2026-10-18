import 'package:flutter/foundation.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/core/utils/zoned_time.dart';
import 'package:peopleos/l10n/generated/app_localizations.dart';

enum RequestStatus {
  pending('PENDING'),
  approved('APPROVED'),
  rejected('REJECTED'),
  cancelled('CANCELLED');

  const RequestStatus(this.wire);
  final String wire;

  String label(AppLocalizations l10n) => switch (this) {
    pending => l10n.requestsStatusPending,
    approved => l10n.requestsStatusApproved,
    rejected => l10n.requestsStatusRejected,
    cancelled => l10n.requestsStatusCancelled,
  };

  static RequestStatus parse(String? raw) => RequestStatus.values.firstWhere(
    (s) => s.wire == raw,
    orElse: () => RequestStatus.pending,
  );
}

@immutable
class CorrectionRequest {
  const CorrectionRequest({
    required this.id,
    required this.workDate,
    required this.reason,
    required this.status,
    this.attendanceId,
    this.requestedCheckInAt,
    this.requestedCheckOutAt,
    this.reviewNote,
    this.reviewedAt,
    this.createdAt,
  });

  final int id;
  final int? attendanceId;
  final DateTime workDate;
  final ZonedTime? requestedCheckInAt;
  final ZonedTime? requestedCheckOutAt;
  final String reason;
  final RequestStatus status;
  final String? reviewNote;
  final ZonedTime? reviewedAt;
  final ZonedTime? createdAt;

  factory CorrectionRequest.fromJson(JsonReader j) => CorrectionRequest(
    id: j.intOrNull('id') ?? 0,
    attendanceId: j.intOrNull('attendance_id'),
    workDate: j.date('work_date') ?? DateTime.now(),
    requestedCheckInAt: j.time('requested_check_in_at'),
    requestedCheckOutAt: j.time('requested_check_out_at'),
    reason: j.str('reason') ?? '',
    status: RequestStatus.parse(j.str('status')),
    reviewNote: j.str('review_note'),
    reviewedAt: j.time('reviewed_at'),
    createdAt: j.time('created_at'),
  );
}

@immutable
class OvertimeRequest {
  const OvertimeRequest({
    required this.id,
    required this.workDate,
    required this.reason,
    required this.status,
    required this.requestedMinutes,
    this.startAt,
    this.endAt,
    this.approvedMinutes,
    this.reviewNote,
    this.reviewedAt,
    this.createdAt,
  });

  final int id;
  final DateTime workDate;
  final ZonedTime? startAt;
  final ZonedTime? endAt;
  final int requestedMinutes;
  final int? approvedMinutes;
  final String reason;
  final RequestStatus status;
  final String? reviewNote;
  final ZonedTime? reviewedAt;
  final ZonedTime? createdAt;

  factory OvertimeRequest.fromJson(JsonReader j) => OvertimeRequest(
    id: j.intOrNull('id') ?? 0,
    workDate: j.date('work_date') ?? DateTime.now(),
    startAt: j.time('start_at'),
    endAt: j.time('end_at'),
    requestedMinutes: j.intOr('requested_minutes', 0),
    approvedMinutes: j.intOrNull('approved_minutes'),
    reason: j.str('reason') ?? '',
    status: RequestStatus.parse(j.str('status')),
    reviewNote: j.str('review_note'),
    reviewedAt: j.time('reviewed_at'),
    createdAt: j.time('created_at'),
  );
}
