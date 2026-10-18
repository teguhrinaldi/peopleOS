import 'package:flutter/material.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/utils/zoned_time.dart';
import 'package:peopleos/core/widgets/app_card.dart';
import 'package:peopleos/core/widgets/status_badge.dart';
import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Badge for a record: "Present" family for normal days, workflow states
/// otherwise. Lateness is shown as separate text, like the reference.
StatusBadge attendanceBadge(
  AppLocalizations l10n,
  AttendanceRecord r, {
  bool dense = false,
}) {
  switch (r.status) {
    case 'CHECKED_IN':
      return StatusBadge(
        label: l10n.attendanceBadgeWorking,
        tone: BadgeTone.primary,
        dense: dense,
      );
    case 'CORRECTED':
      return StatusBadge(
        label: l10n.attendanceBadgeCorrected,
        tone: BadgeTone.primary,
        dense: dense,
      );
    case 'CORRECTION_REQUESTED':
      return StatusBadge(
        label: l10n.attendanceBadgeCorrection,
        tone: BadgeTone.warning,
        dense: dense,
      );
    case 'PENDING':
      return StatusBadge(
        label: l10n.attendanceBadgeInReview,
        tone: BadgeTone.warning,
        dense: dense,
      );
    case 'REJECTED':
      return StatusBadge.rejected(dense: dense);
    default:
      return StatusBadge(
        label: l10n.attendanceBadgePresent,
        tone: BadgeTone.success,
        dense: dense,
      );
  }
}

class AttendanceRow extends StatelessWidget {
  const AttendanceRow({super.key, required this.record, this.onTap});

  final AttendanceRecord record;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final l10n = context.l10n;
    final date = context.fmt.weekdayShort(record.workDate);
    final times =
        '${record.checkInTime ?? '--:--'} - ${record.checkOutTime ?? '--:--'}';
    final late = record.isLate;
    return AppCard(
      onTap: onTap,
      semanticLabel: late
          ? l10n.attendanceRowSemanticLate(date, times, record.lateMinutes)
          : l10n.attendanceRowSemanticOnTime(date, times),
      padding: const EdgeInsets.symmetric(
        horizontal: PeopleSpacing.md,
        vertical: PeopleSpacing.sm + 2,
      ),
      shadow: false,
      child: Row(
        children: [
          IconTile(
            icon: Icons.timer_outlined,
            color: late ? c.errorText : c.primaryText,
            size: 36,
            iconSize: 19,
          ),
          const SizedBox(width: PeopleSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(date, style: t.titleSmall),
                const SizedBox(height: 2),
                Text(
                  times,
                  style: t.bodySmall!.copyWith(color: c.textSecondary),
                ),
                if (record.checkInTime != null)
                  Text(
                    late
                        ? l10n.attendanceLate(
                            formatDurationL10n(l10n, record.lateMinutes),
                          )
                        : l10n.attendanceOnTime,
                    style: t.bodySmall!.copyWith(
                      color: late ? c.errorText : c.textMuted,
                      fontWeight: late ? FontWeight.w600 : FontWeight.w400,
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(width: PeopleSpacing.xs),
          attendanceBadge(l10n, record),
        ],
      ),
    );
  }
}

/// A locally recorded action that the server has not confirmed yet.
class PendingActionRow extends StatelessWidget {
  const PendingActionRow({super.key, required this.item, this.onTap});

  final OutboxItem item;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final at = ZonedTime(
      item.deviceCapturedAt,
      Duration(minutes: item.utcOffsetMinutes),
    );
    final failed = item.syncState == SyncState.syncFailed;
    final l10n = context.l10n;
    return AppCard(
      onTap: onTap,
      shadow: false,
      borderColor: (failed ? c.error : c.warning).withValues(alpha: 0.4),
      padding: const EdgeInsets.symmetric(
        horizontal: PeopleSpacing.md,
        vertical: PeopleSpacing.sm + 2,
      ),
      child: Row(
        children: [
          IconTile(
            icon: failed
                ? Icons.error_outline_rounded
                : Icons.cloud_upload_outlined,
            color: failed ? c.errorText : c.warningText,
            size: 36,
            iconSize: 19,
          ),
          const SizedBox(width: PeopleSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${item.type.label(l10n)} · ${at.hhmm}',
                  style: t.titleSmall,
                ),
                Text(
                  failed
                      ? (item.errorText(l10n) ?? l10n.attendanceCouldNotSync)
                      : l10n.attendanceSavedOnDevice,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: t.bodySmall!.copyWith(color: c.textSecondary),
                ),
              ],
            ),
          ),
          const SizedBox(width: PeopleSpacing.xs),
          StatusBadge(
            label: failed
                ? l10n.attendanceSyncFailed
                : l10n.attendancePendingSync,
            tone: failed ? BadgeTone.error : BadgeTone.warning,
            dense: true,
          ),
        ],
      ),
    );
  }
}
