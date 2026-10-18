import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:peopleos/app/router.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/widgets/buttons.dart';
import 'package:peopleos/core/widgets/skeleton.dart';
import 'package:peopleos/core/widgets/state_views.dart';
import 'package:peopleos/features/leave/domain/leave_models.dart';
import 'package:peopleos/features/leave/presentation/leave_detail.dart';
import 'package:peopleos/features/leave/presentation/leave_providers.dart';
import 'package:peopleos/features/leave/presentation/widgets/leave_widgets.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Leave segment of the Requests tab: balances, filters and own requests.
class LeaveTab extends ConsumerWidget {
  const LeaveTab({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final requests = ref.watch(leaveRequestsProvider);
    final balances = ref.watch(leaveBalancesProvider);
    final filter = ref.watch(leaveFilterProvider);
    final l10n = context.l10n;

    Future<void> refresh() async {
      invalidateLeave(ref);
      try {
        await ref.read(leaveRequestsProvider.future);
      } on Object {
        // The error state renders from the provider.
      }
    }

    return RefreshIndicator(
      onRefresh: refresh,
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.only(bottom: PeopleSpacing.xl),
        children: [
          balances.when(
            skipLoadingOnRefresh: true,
            loading: () => const Padding(
              padding: EdgeInsets.symmetric(horizontal: PeopleSpacing.page),
              child: Skeleton(height: 118, radius: PeopleRadii.card),
            ),
            error: (_, _) => const SizedBox.shrink(),
            data: (list) => LeaveBalanceStrip(balances: list),
          ),
          const SizedBox(height: PeopleSpacing.md),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: PeopleSpacing.page),
            child: PrimaryButton(
              label: l10n.leaveRequestButton,
              icon: Icons.add_rounded,
              onPressed: () => context.push(Routes.newLeave),
            ),
          ),
          const SizedBox(height: PeopleSpacing.md),
          _FilterChips(
            value: filter,
            onChanged: (s) => ref.read(leaveFilterProvider.notifier).set(s),
          ),
          const SizedBox(height: PeopleSpacing.sm),
          requests.when(
            skipLoadingOnRefresh: true,
            loading: () => const Padding(
              padding: EdgeInsets.symmetric(horizontal: PeopleSpacing.page),
              child: Column(
                children: [
                  Skeleton(height: 84, radius: PeopleRadii.card),
                  SizedBox(height: PeopleSpacing.xs),
                  Skeleton(height: 84, radius: PeopleRadii.card),
                ],
              ),
            ),
            error: (e, _) => SizedBox(
              height: 320,
              child: ErrorState(error: e, onRetry: refresh),
            ),
            data: (all) {
              final items = filter == null
                  ? all
                  : all.where((r) => r.status == filter).toList();
              if (items.isEmpty) {
                return Padding(
                  padding: const EdgeInsets.only(top: PeopleSpacing.lg),
                  child: EmptyState(
                    icon: Icons.beach_access_outlined,
                    title: filter == null
                        ? l10n.leaveEmptyTitle
                        : l10n.leaveEmptyFiltered(filter.name),
                    message: filter == null ? l10n.leaveEmptyMessage : null,
                  ),
                );
              }
              return Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: PeopleSpacing.page,
                ),
                child: Column(
                  children: [
                    for (final r in items) ...[
                      LeaveRequestCard(
                        request: r,
                        onTap: () => showLeaveDetail(context, ref, r),
                      ),
                      const SizedBox(height: PeopleSpacing.xs),
                    ],
                  ],
                ),
              );
            },
          ),
        ],
      ),
    );
  }
}

class _FilterChips extends StatelessWidget {
  const _FilterChips({required this.value, required this.onChanged});

  final LeaveStatus? value;
  final ValueChanged<LeaveStatus?> onChanged;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final options = <(LeaveStatus?, String)>[
      (null, l10n.leaveFilterAll),
      (LeaveStatus.pending, l10n.leaveFilterPending),
      (LeaveStatus.approved, l10n.leaveFilterApproved),
      (LeaveStatus.draft, l10n.leaveFilterDrafts),
      (LeaveStatus.rejected, l10n.leaveFilterRejected),
      (LeaveStatus.cancelled, l10n.leaveFilterCancelled),
    ];
    return SizedBox(
      height: kMinTap,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: PeopleSpacing.page),
        itemCount: options.length,
        separatorBuilder: (_, _) => const SizedBox(width: PeopleSpacing.xs),
        itemBuilder: (context, i) {
          final (status, label) = options[i];
          return _Chip(
            label: label,
            selected: status == value,
            onTap: () => onChanged(status),
          );
        },
      ),
    );
  }
}

class _Chip extends StatelessWidget {
  const _Chip({
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    return Semantics(
      container: true,
      button: true,
      selected: selected,
      label: context.l10n.leaveFilterSemantics(label),
      excludeSemantics: true,
      child: Center(
        child: Material(
          color: selected ? c.primarySoft : c.surface,
          shape: StadiumBorder(
            side: BorderSide(
              color: selected ? c.primary.withValues(alpha: 0.5) : c.border,
            ),
          ),
          child: InkWell(
            customBorder: const StadiumBorder(),
            onTap: onTap,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 13),
              child: Text(
                label,
                style: Theme.of(context).textTheme.labelLarge!.copyWith(
                  fontSize: 13,
                  color: selected ? c.primaryText : c.textSecondary,
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
