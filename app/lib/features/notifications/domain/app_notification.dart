import 'package:flutter/foundation.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/core/utils/zoned_time.dart';
import 'package:peopleos/l10n/generated/app_localizations.dart';

/// Where tapping a notification should take the user.
enum NotificationTarget { leave, correction, overtime, payslip, document, none }

@immutable
class AppNotification {
  const AppNotification({
    required this.id,
    required this.type,
    required this.title,
    required this.body,
    required this.isRead,
    this.entityType,
    this.entityId,
    this.createdAt,
  });

  final int id;

  /// e.g. `leave.approved`, `attendance_correction.rejected`.
  final String type;
  final String title;
  final String body;
  final bool isRead;
  final String? entityType;
  final int? entityId;
  final ZonedTime? createdAt;

  NotificationTarget get target {
    final e = entityType ?? '';
    if (e.endsWith('LeaveRequest') || type.startsWith('leave.')) {
      return NotificationTarget.leave;
    }
    if (e.endsWith('AttendanceCorrection') ||
        type.startsWith('attendance_correction.')) {
      return NotificationTarget.correction;
    }
    if (e.endsWith('OvertimeRequest') || type.startsWith('overtime.')) {
      return NotificationTarget.overtime;
    }
    if (e.endsWith('Payslip') || type.startsWith('payslip.')) {
      return NotificationTarget.payslip;
    }
    if (e.endsWith('Document') || type.startsWith('document.')) {
      return NotificationTarget.document;
    }
    return NotificationTarget.none;
  }

  /// Localized title for known types; unknown types keep the server title.
  String titleFor(AppLocalizations l10n) => switch (type) {
    'leave.pending_review' => l10n.notifTypeLeavePendingReview,
    'leave.approved' => l10n.notifTypeLeaveApproved,
    'leave.rejected' => l10n.notifTypeLeaveRejected,
    'attendance_correction.approved' => l10n.notifTypeCorrectionApproved,
    'attendance_correction.rejected' => l10n.notifTypeCorrectionRejected,
    'overtime.approved' => l10n.notifTypeOvertimeApproved,
    'overtime.rejected' => l10n.notifTypeOvertimeRejected,
    'payslip.available' => l10n.notifTypePayslipAvailable,
    'document.expiring' => l10n.notifTypeDocumentExpiring,
    'document.expired' => l10n.notifTypeDocumentExpired,
    _ => title,
  };

  /// Approved / rejected / pending flavour for the icon tint.
  String get outcome {
    final dot = type.lastIndexOf('.');
    return dot < 0 ? type : type.substring(dot + 1);
  }

  AppNotification markedRead() => AppNotification(
    id: id,
    type: type,
    title: title,
    body: body,
    isRead: true,
    entityType: entityType,
    entityId: entityId,
    createdAt: createdAt,
  );

  factory AppNotification.fromJson(JsonReader j) => AppNotification(
    id: j.intOrNull('id') ?? 0,
    type: j.str('type') ?? '',
    title: j.str('title') ?? '',
    body: j.str('body') ?? '',
    isRead: j.boolOr('is_read', false),
    entityType: j.str('entity_type'),
    entityId: j.intOrNull('entity_id'),
    createdAt: j.time('created_at'),
  );
}

@immutable
class NotificationPage {
  const NotificationPage({
    required this.items,
    required this.unreadCount,
    required this.hasMore,
  });

  final List<AppNotification> items;
  final int unreadCount;
  final bool hasMore;
}

/// The signed-in user's in-app inbox.
abstract class NotificationsRepository {
  Future<NotificationPage> list({int page = 1});
  Future<int> unreadCount();
  Future<void> markRead(int id);
  Future<int> markAllRead();
}
