import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/app/router.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/utils/formatters.dart';
import 'package:peopleos/core/utils/responsive.dart';
import 'package:peopleos/core/widgets/detail_pane.dart';
import 'package:peopleos/core/widgets/app_card.dart';
import 'package:peopleos/core/widgets/buttons.dart';
import 'package:peopleos/core/widgets/list_widgets.dart';
import 'package:peopleos/core/widgets/sheets_dialogs.dart';
import 'package:peopleos/core/widgets/skeleton.dart';
import 'package:peopleos/core/widgets/status_badge.dart';
import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/features/attendance/presentation/widgets/attendance_row.dart';
import 'package:peopleos/l10n/l10n.dart';

final attendancePaneProvider = detailPaneProvider();

Future<void> showAttendanceDetail(
  BuildContext context,
  WidgetRef ref,
  AttendanceRecord r,
) async {
  if (context.isWide) {
    ref
        .read(attendancePaneProvider.notifier)
        .show(
          Column(
            key: ValueKey(r.id),
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                context.fmt.weekdayLong(r.workDate),
                style: Theme.of(context).textTheme.titleLarge,
              ),
              const SizedBox(height: PeopleSpacing.md),
              AttendanceDetailSheet(record: r, inPane: true),
            ],
          ),
        );
    return;
  }
  await _showSheet(context, r);
}

Future<void> _showSheet(BuildContext context, AttendanceRecord r) {
  return showAppSheet<void>(
    context,
    title: context.fmt.weekdayLong(r.workDate),
    builder: (_) => AttendanceDetailSheet(record: r),
  );
}

class AttendanceDetailSheet extends StatelessWidget {
  const AttendanceDetailSheet({
    super.key,
    required this.record,
    this.inPane = false,
  });

  final AttendanceRecord record;

  /// Shown in the tablet pane (not a modal): no route to pop.
  final bool inPane;

  bool get _canCorrect =>
      DateTime.now().difference(record.workDate).inDays <= 30 &&
      record.status != 'CORRECTION_REQUESTED';

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final r = record;
    final l10n = context.l10n;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            attendanceBadge(l10n, r),
            const SizedBox(width: PeopleSpacing.xs),
            if (r.isLate)
              StatusBadge.late(
                label: l10n.attendanceLate(
                  formatDurationL10n(l10n, r.lateMinutes),
                ),
              )
            else if (r.checkInTime != null)
              const StatusBadge.onTime(),
            if (r.isEarlyLeave) ...[
              const SizedBox(width: PeopleSpacing.xs),
              StatusBadge(
                label: l10n.attendanceLeftEarly(
                  formatDurationL10n(l10n, r.earlyLeaveMinutes),
                ),
                tone: BadgeTone.warning,
              ),
            ],
          ],
        ),
        const SizedBox(height: PeopleSpacing.md),
        AppCard(
          shadow: false,
          padding: EdgeInsets.zero,
          child: Column(
            children: [
              KeyValueRow(
                label: l10n.attendanceKvCheckIn,
                value: r.checkInTime ?? '-',
              ),
              const RowDivider(),
              KeyValueRow(
                label: l10n.attendanceKvCheckOut,
                value: r.checkOutTime ?? '-',
              ),
              const RowDivider(),
              KeyValueRow(
                label: l10n.attendanceKvWorked,
                value: r.checkOutTime == null
                    ? '-'
                    : formatDurationL10n(l10n, r.workedMinutes),
              ),
              const RowDivider(),
              KeyValueRow(
                label: l10n.attendanceKvShift,
                value: r.shiftName ?? '-',
              ),
              const RowDivider(),
              KeyValueRow(
                label: l10n.attendanceKvLocation,
                value: r.locationName ?? '-',
              ),
            ],
          ),
        ),
        if (r.checkIn != null) ...[
          const SizedBox(height: PeopleSpacing.md),
          _EventCard(title: l10n.attendanceCheckInDetails, event: r.checkIn!),
        ],
        if (r.checkOut != null) ...[
          const SizedBox(height: PeopleSpacing.sm),
          _EventCard(title: l10n.attendanceCheckOutDetails, event: r.checkOut!),
        ],
        if (r.reviewNote != null && r.reviewNote!.isNotEmpty) ...[
          const SizedBox(height: PeopleSpacing.md),
          Text(l10n.attendanceReviewNote, style: t.titleSmall),
          const SizedBox(height: 2),
          Text(
            r.reviewNote!,
            style: t.bodyMedium!.copyWith(color: c.textSecondary),
          ),
        ],
        if (_canCorrect) ...[
          const SizedBox(height: PeopleSpacing.lg),
          OutlineButton(
            label: l10n.attendanceRequestCorrection,
            icon: Icons.edit_calendar_outlined,
            onPressed: () {
              if (!inPane) Navigator.of(context).pop();
              context.push(Routes.newCorrection, extra: r.workDate);
            },
          ),
        ],
      ],
    );
  }
}

class _EventCard extends StatelessWidget {
  const _EventCard({required this.title, required this.event});
  final String title;
  final AttendanceEvent event;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final inside = event.insideGeofence;
    return AppCard(
      shadow: false,
      color: c.surfaceMuted,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: t.titleSmall),
          const SizedBox(height: PeopleSpacing.xs),
          Wrap(
            spacing: PeopleSpacing.xs,
            runSpacing: PeopleSpacing.xs,
            children: [
              if (event.distanceMeters != null)
                StatusBadge(
                  label: formatDistance(
                    event.distanceMeters!,
                    locale: context.fmt.tag,
                  ),
                  tone: BadgeTone.neutral,
                  icon: Icons.place_outlined,
                ),
              if (inside != null)
                StatusBadge(
                  label: inside
                      ? context.l10n.attendanceInsideGeofence
                      : context.l10n.attendanceOutsideGeofence,
                  tone: inside ? BadgeTone.success : BadgeTone.warning,
                  icon: inside
                      ? Icons.check_circle_outline
                      : Icons.warning_amber_rounded,
                ),
              if (event.isOfflineSync)
                StatusBadge(
                  label: context.l10n.attendanceRecordedOffline,
                  tone: BadgeTone.info,
                  icon: Icons.cloud_off_outlined,
                ),
            ],
          ),
          if (event.hasSelfie && event.selfieUrl != null) ...[
            const SizedBox(height: PeopleSpacing.sm),
            _SelfieThumb(url: event.selfieUrl!),
          ],
        ],
      ),
    );
  }
}

/// Selfies are private: fetched with the bearer header, never via a bare URL.
final _selfieProvider = FutureProvider.autoDispose.family<Uint8List, String>(
  (ref, url) => ref.watch(attendanceRepositoryProvider).selfieBytes(url),
);

class _SelfieThumb extends ConsumerWidget {
  const _SelfieThumb({required this.url});
  final String url;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final photo = ref.watch(_selfieProvider(url));
    return photo.when(
      loading: () =>
          const Skeleton(width: 96, height: 96, radius: PeopleRadii.tile),
      error: (_, _) => Row(
        children: [
          Icon(
            Icons.image_not_supported_outlined,
            color: context.colors.textMuted,
          ),
          const SizedBox(width: PeopleSpacing.xs),
          Text(
            context.l10n.attendanceSelfieUnavailable,
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
      ),
      data: (bytes) => ClipRRect(
        borderRadius: PeopleRadii.tileRadius,
        child: Image.memory(
          bytes,
          width: 96,
          height: 96,
          cacheWidth: 192,
          fit: BoxFit.cover,
          semanticLabel: context.l10n.attendanceSelfieSemantic,
        ),
      ),
    );
  }
}
