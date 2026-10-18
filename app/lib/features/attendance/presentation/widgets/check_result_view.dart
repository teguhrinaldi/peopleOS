import 'package:flutter/material.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/utils/formatters.dart';
import 'package:peopleos/core/utils/zoned_time.dart';
import 'package:peopleos/core/widgets/app_card.dart';
import 'package:peopleos/core/widgets/buttons.dart';
import 'package:peopleos/features/attendance/application/attendance_sync_service.dart';
import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Success state after a check: confirmed by the server, or saved offline.
class CheckResultView extends StatelessWidget {
  const CheckResultView({
    super.key,
    required this.type,
    required this.outcome,
    required this.onDone,
  });

  final CheckType type;
  final SubmitOutcome outcome;
  final VoidCallback onDone;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final l10n = context.l10n;
    final offline = outcome.kind == SubmitKind.savedOffline;
    final record = outcome.result?.attendance;
    final at = ZonedTime(
      outcome.item.deviceCapturedAt,
      Duration(minutes: outcome.item.utcOffsetMinutes),
    );
    final time = type == CheckType.checkIn
        ? (record?.checkInTime ?? at.hhmm)
        : (record?.checkOutTime ?? at.hhmm);
    final isIn = type == CheckType.checkIn;
    final tint = offline ? c.warning : c.success;
    final fg = offline ? c.warningText : c.successText;
    final reduce = PeopleMotion.reduced(context);

    return Padding(
      padding: const EdgeInsets.all(PeopleSpacing.page),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          TweenAnimationBuilder<double>(
            tween: Tween(begin: reduce ? 1 : 0.6, end: 1),
            duration: PeopleMotion.of(
              context,
              const Duration(milliseconds: 450),
            ),
            curve: Curves.elasticOut,
            builder: (context, scale, child) =>
                Transform.scale(scale: scale, child: child),
            child: Container(
              width: 96,
              height: 96,
              decoration: BoxDecoration(
                color: tint.withValues(alpha: 0.15),
                shape: BoxShape.circle,
              ),
              child: Icon(
                offline
                    ? Icons.cloud_upload_outlined
                    : Icons.check_circle_rounded,
                size: 52,
                color: fg,
              ),
            ),
          ),
          const SizedBox(height: PeopleSpacing.lg),
          Semantics(
            liveRegion: true,
            child: Text(
              offline
                  ? l10n.checkResultSavedOnDevice
                  : isIn
                  ? l10n.checkResultCheckedInAt(time)
                  : l10n.checkResultCheckedOutAt(time),
              style: t.headlineSmall,
              textAlign: TextAlign.center,
            ),
          ),
          const SizedBox(height: PeopleSpacing.xs),
          Text(
            offline
                ? (isIn
                      ? l10n.checkResultOfflineInBody(time)
                      : l10n.checkResultOfflineOutBody(time))
                : _summary(l10n, record),
            style: t.bodyMedium!.copyWith(color: c.textSecondary),
            textAlign: TextAlign.center,
          ),
          if (outcome.result?.distanceMeters != null && !offline) ...[
            const SizedBox(height: PeopleSpacing.md),
            AppCard(
              shadow: false,
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(Icons.place_outlined, size: 18, color: c.textMuted),
                  const SizedBox(width: PeopleSpacing.xs),
                  Flexible(
                    child: Text(
                      l10n.checkDistanceFromSite(
                        formatDistance(
                          outcome.result!.distanceMeters!,
                          locale: context.fmt.tag,
                        ),
                        outcome.item.siteName ?? l10n.checkWorkSiteFallback,
                      ),
                      style: t.bodyMedium,
                    ),
                  ),
                ],
              ),
            ),
          ],
          const SizedBox(height: PeopleSpacing.xl),
          PrimaryButton(label: l10n.commonDone, onPressed: onDone),
        ],
      ),
    );
  }

  String _summary(AppLocalizations l10n, AttendanceRecord? record) {
    if (record == null) return l10n.checkSummaryRecorded;
    if (type == CheckType.checkIn) {
      return record.isLate
          ? l10n.checkSummaryLate(formatDurationL10n(l10n, record.lateMinutes))
          : l10n.checkSummaryOnTime;
    }
    return l10n.checkSummaryWorked(
      formatDurationL10n(l10n, record.workedMinutes),
    );
  }
}
