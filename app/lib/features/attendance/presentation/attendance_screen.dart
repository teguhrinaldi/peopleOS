import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:peopleos/app/router.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/utils/responsive.dart';
import 'package:peopleos/core/widgets/app_card.dart';
import 'package:peopleos/core/widgets/master_detail.dart';
import 'package:peopleos/core/widgets/skeleton.dart';
import 'package:peopleos/core/widgets/state_views.dart';
import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';
import 'package:peopleos/features/attendance/presentation/attendance_providers.dart';
import 'package:peopleos/features/attendance/presentation/outbox_controller.dart';
import 'package:peopleos/features/attendance/presentation/widgets/attendance_detail_sheet.dart';
import 'package:peopleos/features/attendance/presentation/widgets/attendance_row.dart';
import 'package:peopleos/features/attendance/presentation/widgets/outbox_sync_chip.dart';
import 'package:peopleos/l10n/l10n.dart';

class AttendanceScreen extends ConsumerWidget {
  const AttendanceScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final month = ref.watch(selectedMonthProvider);
    final history = ref.watch(attendanceHistoryProvider(month));
    final outbox = ref.watch(outboxControllerProvider);
    final waiting = [...outbox.active, ...outbox.failed]
      ..sort((a, b) => b.deviceCapturedAt.compareTo(a.deviceCapturedAt));

    final wide = context.isWide;
    final master = ContentWidth(
      maxWidth: wide ? double.infinity : Breakpoints.formMax + 200,
      child: Column(
        children: [
          _Header(onCorrection: () => context.push(Routes.newCorrection)),
          _MonthSwitcher(month: month),
          Expanded(
            child: RefreshIndicator(
              onRefresh: () async {
                ref.invalidate(attendanceHistoryProvider(month));
                await ref
                    .read(outboxControllerProvider.notifier)
                    .syncNow(force: false);
              },
              child: history.when(
                skipLoadingOnRefresh: true,
                loading: () => const SkeletonList(itemHeight: 76),
                error: (e, _) => LayoutBuilder(
                  builder: (context, box) => SingleChildScrollView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    child: SizedBox(
                      height: box.maxHeight,
                      child: ErrorState(
                        error: e,
                        onRetry: () =>
                            ref.invalidate(attendanceHistoryProvider(month)),
                      ),
                    ),
                  ),
                ),
                data: (records) => _List(
                  month: month,
                  records: records,
                  onOpen: (r) => showAttendanceDetail(context, ref, r),
                  waiting: waiting.where((i) {
                    final w = i.deviceCapturedAt.toLocal();
                    return w.year == month.year && w.month == month.month;
                  }).toList(),
                ),
              ),
            ),
          ),
        ],
      ),
    );
    return SafeArea(
      bottom: false,
      child: wide
          ? MasterDetail(
              master: master,
              detail: ref.watch(attendancePaneProvider),
              masterWidth: 440,
              emptyTitle: context.l10n.attendanceSelectDay,
              emptyIcon: Icons.event_note_outlined,
            )
          : master,
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.onCorrection});
  final VoidCallback onCorrection;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        PeopleSpacing.page,
        PeopleSpacing.sm,
        PeopleSpacing.xs,
        0,
      ),
      child: Row(
        children: [
          Expanded(
            child: Semantics(
              header: true,
              child: Text(
                context.l10n.attendanceHistoryTitle,
                style: Theme.of(context).textTheme.titleLarge,
              ),
            ),
          ),
          const OutboxSyncChip(),
          IconButton(
            tooltip: context.l10n.attendanceRequestCorrection,
            constraints: const BoxConstraints(
              minWidth: kMinTap,
              minHeight: kMinTap,
            ),
            icon: Icon(
              Icons.edit_calendar_outlined,
              color: context.colors.primaryText,
            ),
            onPressed: onCorrection,
          ),
        ],
      ),
    );
  }
}

class _MonthSwitcher extends ConsumerWidget {
  const _MonthSwitcher({required this.month});
  final DateTime month;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final notifier = ref.read(selectedMonthProvider.notifier);
    final isCurrent = notifier.isCurrentMonth;
    final c = context.colors;
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        PeopleSpacing.page,
        PeopleSpacing.xs,
        PeopleSpacing.page,
        PeopleSpacing.xs,
      ),
      child: AppCard(
        shadow: false,
        padding: EdgeInsets.zero,
        child: Row(
          children: [
            IconButton(
              tooltip: context.l10n.attendancePreviousMonth,
              constraints: const BoxConstraints(
                minWidth: kMinTap,
                minHeight: kMinTap,
              ),
              icon: const Icon(Icons.chevron_left_rounded),
              onPressed: () => notifier.shift(-1),
            ),
            Expanded(
              child: Semantics(
                liveRegion: true,
                child: Text(
                  context.fmt.monthYear(month),
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.titleSmall,
                ),
              ),
            ),
            IconButton(
              tooltip: context.l10n.attendanceNextMonth,
              constraints: const BoxConstraints(
                minWidth: kMinTap,
                minHeight: kMinTap,
              ),
              icon: Icon(
                Icons.chevron_right_rounded,
                color: isCurrent ? c.borderStrong : null,
              ),
              onPressed: isCurrent ? null : () => notifier.shift(1),
            ),
          ],
        ),
      ),
    );
  }
}

class _List extends StatelessWidget {
  const _List({
    required this.month,
    required this.records,
    required this.waiting,
    required this.onOpen,
  });

  final void Function(AttendanceRecord) onOpen;
  final DateTime month;
  final List<AttendanceRecord> records;
  final List<OutboxItem> waiting;

  @override
  Widget build(BuildContext context) {
    if (records.isEmpty && waiting.isEmpty) {
      return LayoutBuilder(
        builder: (context, box) => SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          child: SizedBox(
            height: box.maxHeight,
            child: EmptyState(
              icon: Icons.event_note_outlined,
              title: context.l10n.attendanceEmptyTitle,
              message: context.l10n.attendanceEmptyMessage(
                context.fmt.monthYear(month),
              ),
            ),
          ),
        ),
      );
    }
    final present = records.where((r) => r.checkInTime != null).length;
    final late = records.where((r) => r.isLate).length;
    final minutes = records.fold<int>(0, (s, r) => s + r.workedMinutes);

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(
        PeopleSpacing.page,
        PeopleSpacing.xs,
        PeopleSpacing.page,
        PeopleSpacing.xl,
      ),
      children: [
        _SummaryRow(present: present, late: late, minutes: minutes),
        const SizedBox(height: PeopleSpacing.md),
        for (final item in waiting) ...[
          PendingActionRow(item: item, onTap: () => context.push(Routes.sync)),
          const SizedBox(height: PeopleSpacing.xs),
        ],
        for (final r in records) ...[
          AttendanceRow(record: r, onTap: () => onOpen(r)),
          const SizedBox(height: PeopleSpacing.xs),
        ],
      ],
    );
  }
}

class _SummaryRow extends StatelessWidget {
  const _SummaryRow({
    required this.present,
    required this.late,
    required this.minutes,
  });

  final int present;
  final int late;
  final int minutes;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: _Stat(
            label: context.l10n.attendanceStatPresent,
            value: '$present',
            color: context.colors.successText,
          ),
        ),
        const SizedBox(width: PeopleSpacing.xs),
        Expanded(
          child: _Stat(
            label: context.l10n.attendanceStatLate,
            value: '$late',
            color: late > 0
                ? context.colors.errorText
                : context.colors.textPrimary,
          ),
        ),
        const SizedBox(width: PeopleSpacing.xs),
        Expanded(
          child: _Stat(
            label: context.l10n.attendanceStatHours,
            value: context.fmt.number(minutes / 60, decimals: 1),
            color: context.colors.primaryText,
          ),
        ),
      ],
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({required this.label, required this.value, required this.color});
  final String label;
  final String value;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return Semantics(
      label: context.l10n.attendanceStatSemantic(label, value),
      excludeSemantics: true,
      child: AppCard(
        shadow: false,
        padding: const EdgeInsets.symmetric(
          vertical: PeopleSpacing.sm,
          horizontal: PeopleSpacing.sm,
        ),
        child: Column(
          children: [
            Text(value, style: t.headlineSmall!.copyWith(color: color)),
            Text(label, style: t.labelMedium),
          ],
        ),
      ),
    );
  }
}
