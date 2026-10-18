import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:peopleos/app/router.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/utils/responsive.dart';
import 'package:peopleos/core/widgets/skeleton.dart';
import 'package:peopleos/core/widgets/state_views.dart';
import 'package:peopleos/features/attendance/domain/effective_today.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';
import 'package:peopleos/features/attendance/presentation/attendance_providers.dart';
import 'package:peopleos/features/attendance/presentation/outbox_controller.dart';
import 'package:peopleos/features/attendance/presentation/widgets/outbox_sync_chip.dart';
import 'package:peopleos/features/auth/presentation/auth_controller.dart';
import 'package:peopleos/features/auth/domain/session.dart';
import 'package:peopleos/features/home/presentation/widgets/attendance_hero_card.dart';
import 'package:peopleos/features/home/presentation/widgets/home_header.dart';
import 'package:peopleos/features/home/presentation/widgets/quick_actions.dart';
import 'package:peopleos/features/home/presentation/widgets/recent_activity.dart';
import 'package:peopleos/features/home/presentation/widgets/requests_summary_card.dart';
import 'package:peopleos/features/documents/presentation/documents_providers.dart';
import 'package:peopleos/features/leave/presentation/leave_providers.dart';
import 'package:peopleos/features/payslip/presentation/payslip_providers.dart';
import 'package:peopleos/features/notifications/presentation/notifications_controller.dart';
import 'package:peopleos/features/requests/presentation/requests_screen.dart';
import 'package:peopleos/features/home/presentation/widgets/shift_card.dart';
import 'package:peopleos/features/home/presentation/widgets/updates_strip.dart';
import 'package:peopleos/l10n/l10n.dart';

class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  Future<void> _refresh(WidgetRef ref) async {
    ref.invalidate(attendanceHistoryProvider);
    ref.invalidate(serverTodayProvider);
    ref.invalidate(unreadCountProvider);
    ref
      ..invalidate(leaveRequestsProvider)
      ..invalidate(latestPayslipProvider)
      ..invalidate(attentionDocumentsProvider);
    unawaited(
      ref.read(outboxControllerProvider.notifier).syncNow(force: false),
    );
    try {
      await ref.read(serverTodayProvider.future);
    } on Object {
      // The error state is rendered from the provider, not the spinner.
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final employee = ref.watch(authControllerProvider).employee;
    final today = ref.watch(effectiveTodayProvider);
    if (employee == null) return const SizedBox.shrink();

    return today.when(
      skipLoadingOnRefresh: true,
      loading: () => const _HomeSkeleton(),
      error: (e, _) => _HomeError(
        error: e,
        onRetry: () => ref.invalidate(serverTodayProvider),
      ),
      data: (data) => RefreshIndicator(
        onRefresh: () => _refresh(ref),
        child: _HomeContent(data: data, employee: employee),
      ),
    );
  }
}

class _HomeContent extends ConsumerWidget {
  const _HomeContent({required this.data, required this.employee});

  final EffectiveToday data;
  final Employee employee;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final wide = context.isWide;
    final l10n = context.l10n;
    final hour = data.server.serverTime.wall.hour;

    void goTab(String route) => context.go(route);

    final header = HomeHeader(
      employee: employee,
      hour: hour,
      unread: ref.watch(unreadCountProvider).value ?? 0,
      onBell: () => context.push(Routes.notifications),
    );

    final hero = AttendanceHeroCard(
      today: data,
      onCheckIn: () => context.push(Routes.check(CheckType.checkIn)),
      onCheckOut: () => context.push(Routes.check(CheckType.checkOut)),
      onOpenRequests: () => goTab(Routes.requests),
      onOpenSync: () => context.push(Routes.sync),
    );

    final actions = QuickActionsGrid(
      actions: [
        QuickAction(
          l10n.homeQuickLeave,
          Icons.beach_access_outlined,
          ActionColors.blue,
          () => context.push(Routes.newLeave),
        ),
        QuickAction(
          l10n.homeQuickPayslip,
          Icons.receipt_long_outlined,
          ActionColors.orange,
          () => goTab(Routes.payslip),
        ),
        QuickAction(
          l10n.homeQuickAttendance,
          Icons.timer_outlined,
          ActionColors.teal,
          () => goTab(Routes.attendance),
        ),
        QuickAction(
          l10n.homeQuickRequests,
          Icons.assignment_outlined,
          ActionColors.purple,
          () => goTab(Routes.requests),
        ),
        QuickAction(
          l10n.homeQuickDocuments,
          Icons.folder_open_outlined,
          ActionColors.cyan,
          () => context.push(Routes.documents),
        ),
        QuickAction(
          l10n.homeQuickMore,
          Icons.apps_rounded,
          context.colors.textSecondary,
          () => context.push(Routes.soon('more')),
        ),
      ],
    );

    final left = <Widget>[
      header,
      const SizedBox(height: PeopleSpacing.sm),
      const Align(alignment: Alignment.centerLeft, child: OutboxSyncChip()),
      const SizedBox(height: PeopleSpacing.sm),
      ShiftCard(today: data),
      const SizedBox(height: PeopleSpacing.md),
      hero,
      const SizedBox(height: PeopleSpacing.sm),
      RequestsSummaryCard(
        onTap: () {
          ref.read(requestsTabProvider.notifier).set(RequestTab.leave);
          goTab(Routes.requests);
        },
      ),
    ];
    final right = <Widget>[
      actions,
      const SizedBox(height: PeopleSpacing.lg),
      const UpdatesStrip(),
      const SizedBox(height: PeopleSpacing.lg),
      RecentActivity(
        onViewAll: () => goTab(Routes.attendance),
        onOpenSync: () => context.push(Routes.sync),
      ),
    ];

    return SafeArea(
      bottom: false,
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(
          PeopleSpacing.page,
          PeopleSpacing.sm,
          PeopleSpacing.page,
          PeopleSpacing.xl,
        ),
        children: [
          ContentWidth(
            maxWidth: Breakpoints.contentMax,
            child: wide
                ? Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        flex: 11,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: left,
                        ),
                      ),
                      const SizedBox(width: PeopleSpacing.xl),
                      Expanded(
                        flex: 10,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            const SizedBox(height: PeopleSpacing.xs),
                            ...right,
                          ],
                        ),
                      ),
                    ],
                  )
                : Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      ...left,
                      const SizedBox(height: PeopleSpacing.md),
                      ...right,
                    ],
                  ),
          ),
        ],
      ),
    );
  }
}

class _HomeSkeleton extends StatelessWidget {
  const _HomeSkeleton();

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: context.l10n.homeLoadingToday,
      child: SafeArea(
        bottom: false,
        child: ContentWidth(
          child: Padding(
            padding: const EdgeInsets.all(PeopleSpacing.page),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Row(
                  children: [
                    Skeleton(width: 48, height: 48, radius: 24),
                    SizedBox(width: PeopleSpacing.sm),
                    Expanded(child: Skeleton(height: 40)),
                  ],
                ),
                const SizedBox(height: PeopleSpacing.lg),
                const Skeleton(height: 96, radius: PeopleRadii.card),
                const SizedBox(height: PeopleSpacing.md),
                const Skeleton(height: 190, radius: PeopleRadii.card),
                const SizedBox(height: PeopleSpacing.md),
                Row(
                  children: [
                    for (var i = 0; i < 3; i++) ...[
                      if (i > 0) const SizedBox(width: PeopleSpacing.sm),
                      const Expanded(
                        child: Skeleton(height: 92, radius: PeopleRadii.card),
                      ),
                    ],
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _HomeError extends StatelessWidget {
  const _HomeError({required this.error, required this.onRetry});
  final Object error;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Container(
        color: context.colors.background,
        child: ErrorState(error: error, onRetry: onRetry),
      ),
    );
  }
}
