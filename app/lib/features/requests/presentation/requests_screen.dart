import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:peopleos/app/router.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/utils/responsive.dart';
import 'package:peopleos/core/widgets/app_card.dart';
import 'package:peopleos/core/widgets/buttons.dart';
import 'package:peopleos/core/widgets/detail_pane.dart';
import 'package:peopleos/core/widgets/master_detail.dart';
import 'package:peopleos/core/widgets/skeleton.dart';
import 'package:peopleos/core/widgets/state_views.dart';
import 'package:peopleos/core/widgets/status_badge.dart';
import 'package:peopleos/features/leave/presentation/leave_tab.dart';
import 'package:peopleos/features/requests/domain/request_models.dart';
import 'package:peopleos/features/requests/presentation/request_detail_sheet.dart';
import 'package:peopleos/features/requests/presentation/requests_providers.dart';
import 'package:peopleos/l10n/l10n.dart';

enum RequestTab { leave, corrections, overtime }

/// Selected segment; other screens (Home, notifications) can switch it.
class RequestsTabNotifier extends Notifier<RequestTab> {
  @override
  RequestTab build() => RequestTab.leave;

  void set(RequestTab tab) => state = tab;
}

final requestsPaneProvider = detailPaneProvider();

final requestsTabProvider = NotifierProvider<RequestsTabNotifier, RequestTab>(
  RequestsTabNotifier.new,
);

class RequestsScreen extends ConsumerWidget {
  const RequestsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tab = ref.watch(requestsTabProvider);
    final wide = context.isWide;
    final pane = ref.watch(requestsPaneProvider);
    final l10n = context.l10n;
    final content = switch (tab) {
      RequestTab.leave => const LeaveTab(),
      RequestTab.corrections => const _CorrectionsList(),
      RequestTab.overtime => const _OvertimeList(),
    };
    ref.listen(
      requestsTabProvider,
      (_, _) => ref.read(requestsPaneProvider.notifier).clear(),
    );
    return SafeArea(
      bottom: false,
      child: ContentWidth(
        maxWidth: wide ? double.infinity : Breakpoints.formMax + 200,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(
                PeopleSpacing.page,
                PeopleSpacing.sm,
                PeopleSpacing.page,
                PeopleSpacing.sm,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Semantics(
                    header: true,
                    child: Text(
                      l10n.requestsTitle,
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                  ),
                  const SizedBox(height: PeopleSpacing.sm),
                  SegmentedTabs<RequestTab>(
                    value: tab,
                    onChanged: ref.read(requestsTabProvider.notifier).set,
                    options: {
                      RequestTab.leave: l10n.requestsTabLeave,
                      RequestTab.corrections: l10n.requestsTabCorrections,
                      RequestTab.overtime: l10n.requestsTabOvertime,
                    },
                  ),
                ],
              ),
            ),
            Expanded(
              child: wide
                  ? MasterDetail(
                      master: content,
                      detail: pane,
                      masterWidth: 440,
                      emptyTitle: l10n.requestsSelectPrompt,
                      emptyIcon: Icons.assignment_outlined,
                    )
                  : content,
            ),
          ],
        ),
      ),
    );
  }
}

/// Pill segmented control from the reference ("My Requests | Balance").
class SegmentedTabs<T> extends StatelessWidget {
  const SegmentedTabs({
    super.key,
    required this.value,
    required this.options,
    required this.onChanged,
  });

  final T value;
  final Map<T, String> options;
  final ValueChanged<T> onChanged;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: c.neutralSoft,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Row(
        children: [
          for (final e in options.entries)
            Expanded(
              child: Semantics(
                container: true,
                button: true,
                selected: e.key == value,
                label: e.value,
                excludeSemantics: true,
                child: GestureDetector(
                  behavior: HitTestBehavior.opaque,
                  onTap: () => onChanged(e.key),
                  child: AnimatedContainer(
                    duration: PeopleMotion.of(context, PeopleMotion.medium),
                    height: 40,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: e.key == value ? c.primary : Colors.transparent,
                      borderRadius: BorderRadius.circular(11),
                    ),
                    child: Text(
                      e.value,
                      style: Theme.of(context).textTheme.labelLarge!.copyWith(
                        color: e.key == value ? c.onPrimary : c.textSecondary,
                      ),
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

StatusBadge requestBadge(
  RequestStatus s,
  AppLocalizations l10n, {
  bool dense = false,
}) {
  switch (s) {
    case RequestStatus.pending:
      return StatusBadge.pending(dense: dense);
    case RequestStatus.approved:
      return StatusBadge.approved(dense: dense);
    case RequestStatus.rejected:
      return StatusBadge.rejected(dense: dense);
    case RequestStatus.cancelled:
      return StatusBadge(
        label: s.label(l10n),
        tone: BadgeTone.neutral,
        dense: dense,
      );
  }
}

class _ListFrame<T> extends StatelessWidget {
  const _ListFrame({
    required this.async,
    required this.onRefresh,
    required this.newLabel,
    required this.onNew,
    required this.emptyTitle,
    required this.emptyMessage,
    required this.itemBuilder,
  });

  final AsyncValue<List<T>> async;
  final Future<void> Function() onRefresh;
  final String newLabel;
  final VoidCallback onNew;
  final String emptyTitle;
  final String emptyMessage;
  final Widget Function(BuildContext, T) itemBuilder;

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: onRefresh,
      child: async.when(
        skipLoadingOnRefresh: true,
        loading: () => const SkeletonList(itemHeight: 80),
        error: (e, _) => LayoutBuilder(
          builder: (context, box) => SingleChildScrollView(
            physics: const AlwaysScrollableScrollPhysics(),
            child: SizedBox(
              height: box.maxHeight,
              child: ErrorState(error: e, onRetry: onRefresh),
            ),
          ),
        ),
        data: (items) => ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.fromLTRB(
            PeopleSpacing.page,
            0,
            PeopleSpacing.page,
            PeopleSpacing.xl,
          ),
          children: [
            PrimaryButton(
              label: newLabel,
              icon: Icons.add_rounded,
              onPressed: onNew,
            ),
            const SizedBox(height: PeopleSpacing.md),
            if (items.isEmpty)
              Padding(
                padding: const EdgeInsets.only(top: PeopleSpacing.xl),
                child: EmptyState(
                  icon: Icons.assignment_outlined,
                  title: emptyTitle,
                  message: emptyMessage,
                ),
              )
            else
              for (final item in items) ...[
                itemBuilder(context, item),
                const SizedBox(height: PeopleSpacing.xs),
              ],
          ],
        ),
      ),
    );
  }
}

class _CorrectionsList extends ConsumerWidget {
  const _CorrectionsList();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final fmt = context.fmt;
    return _ListFrame<CorrectionRequest>(
      async: ref.watch(correctionsProvider),
      onRefresh: () async => ref.refresh(correctionsProvider.future),
      newLabel: l10n.requestsNewCorrection,
      onNew: () => context.push(Routes.newCorrection),
      emptyTitle: l10n.requestsCorrectionsEmptyTitle,
      emptyMessage: l10n.requestsCorrectionsEmptyMessage,
      itemBuilder: (context, r) => _RequestCard(
        icon: Icons.edit_calendar_outlined,
        title: fmt.weekdayShort(r.workDate),
        subtitle: [
          if (r.requestedCheckInAt != null)
            l10n.requestsCorrectionIn(r.requestedCheckInAt!.hhmm),
          if (r.requestedCheckOutAt != null)
            l10n.requestsCorrectionOut(r.requestedCheckOutAt!.hhmm),
        ].join(' · '),
        reason: r.reason,
        badge: requestBadge(r.status, l10n),
        onTap: () => showCorrectionDetail(context, ref, r),
      ),
    );
  }
}

class _OvertimeList extends ConsumerWidget {
  const _OvertimeList();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final fmt = context.fmt;
    return _ListFrame<OvertimeRequest>(
      async: ref.watch(overtimeProvider),
      onRefresh: () async => ref.refresh(overtimeProvider.future),
      newLabel: l10n.requestsNewOvertime,
      onNew: () => context.push(Routes.newOvertime),
      emptyTitle: l10n.requestsOvertimeEmptyTitle,
      emptyMessage: l10n.requestsOvertimeEmptyMessage,
      itemBuilder: (context, r) => _RequestCard(
        icon: Icons.more_time_rounded,
        title: fmt.weekdayShort(r.workDate),
        subtitle: l10n.requestsOvertimeSubtitle(
          r.startAt?.hhmm ?? '--:--',
          r.endAt?.hhmm ?? '--:--',
          formatDurationL10n(l10n, r.requestedMinutes),
        ),
        reason: r.reason,
        badge: requestBadge(r.status, l10n),
        onTap: () => showOvertimeDetail(context, ref, r),
      ),
    );
  }
}

class _RequestCard extends StatelessWidget {
  const _RequestCard({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.reason,
    required this.badge,
    required this.onTap,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final String reason;
  final Widget badge;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    return AppCard(
      onTap: onTap,
      shadow: false,
      padding: const EdgeInsets.all(PeopleSpacing.md),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          IconTile(icon: icon, size: 40),
          const SizedBox(width: PeopleSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: t.titleSmall),
                if (subtitle.isNotEmpty)
                  Text(
                    subtitle,
                    style: t.bodySmall!.copyWith(color: c.textSecondary),
                  ),
                const SizedBox(height: 2),
                Text(
                  reason,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: t.bodySmall!.copyWith(color: c.textMuted),
                ),
              ],
            ),
          ),
          const SizedBox(width: PeopleSpacing.xs),
          badge,
        ],
      ),
    );
  }
}
