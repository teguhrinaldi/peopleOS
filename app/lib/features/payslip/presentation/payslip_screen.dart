import 'dart:async';

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
import 'package:peopleos/features/payslip/domain/payslip.dart';
import 'package:peopleos/features/payslip/presentation/payslip_providers.dart';
import 'package:peopleos/features/payslip/presentation/widgets/payslip_detail_view.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Payslip tab: released payslips, newest first, filterable by year. On
/// tablets the list and the selected payslip sit side by side.
class PayslipScreen extends ConsumerWidget {
  const PayslipScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final wide = context.isWide;
    final selected = ref.watch(selectedPayslipProvider);
    final list = _PayslipList(wide: wide, selectedId: selected);

    return SafeArea(
      bottom: false,
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(
              PeopleSpacing.page,
              PeopleSpacing.sm,
              PeopleSpacing.page,
              PeopleSpacing.xs,
            ),
            child: Align(
              alignment: Alignment.centerLeft,
              child: Semantics(
                header: true,
                child: Text(
                  context.l10n.payslipTitle,
                  style: Theme.of(context).textTheme.titleLarge,
                ),
              ),
            ),
          ),
          Expanded(
            child: wide
                ? MasterDetail(
                    master: list,
                    emptyTitle: context.l10n.payslipSelectPrompt,
                    emptyIcon: Icons.receipt_long_outlined,
                    detail: selected == null
                        ? null
                        : PayslipDetailView(
                            key: ValueKey(selected),
                            id: selected,
                          ),
                  )
                : ContentWidth(maxWidth: 560, child: list),
          ),
        ],
      ),
    );
  }
}

class _PayslipList extends ConsumerWidget {
  const _PayslipList({required this.wide, required this.selectedId});

  final bool wide;
  final int? selectedId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(payslipsProvider);
    final controller = ref.read(payslipsProvider.notifier);
    final year = ref.watch(payslipYearProvider);
    final years = ref.watch(payslipYearsProvider).value ?? const <int>[];

    // Pre-select the newest payslip on wide screens.
    ref.listen(payslipsProvider, (_, next) {
      final items = next.value?.items;
      if (wide && items != null && items.isNotEmpty && selectedId == null) {
        Future.microtask(
          () => ref.read(selectedPayslipProvider.notifier).set(items.first.id),
        );
      }
    });

    return Column(
      children: [
        if (years.length > 1)
          SizedBox(
            height: kMinTap,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(
                horizontal: PeopleSpacing.page,
              ),
              children: [
                _YearChip(
                  isAll: true,
                  label: context.l10n.payslipYearAll,
                  selected: year == null,
                  onTap: () => ref.read(payslipYearProvider.notifier).set(null),
                ),
                for (final y in years)
                  _YearChip(
                    label: '$y',
                    selected: year == y,
                    onTap: () => ref.read(payslipYearProvider.notifier).set(y),
                  ),
              ],
            ),
          ),
        Expanded(
          child: state.when(
            skipLoadingOnRefresh: true,
            loading: () => const SkeletonList(itemHeight: 88),
            error: (e, _) => ErrorState(error: e, onRetry: controller.refresh),
            data: (s) => RefreshIndicator(
              onRefresh: controller.refresh,
              child: s.items.isEmpty
                  ? LayoutBuilder(
                      builder: (context, box) => SingleChildScrollView(
                        physics: const AlwaysScrollableScrollPhysics(),
                        child: SizedBox(
                          height: box.maxHeight,
                          child: EmptyState(
                            icon: Icons.receipt_long_outlined,
                            title: context.l10n.payslipEmptyTitle,
                            message: context.l10n.payslipEmptyMessage,
                          ),
                        ),
                      ),
                    )
                  : NotificationListener<ScrollNotification>(
                      onNotification: (n) {
                        if (n.metrics.extentAfter < 300) {
                          unawaited(controller.loadMore());
                        }
                        return false;
                      },
                      child: ListView.separated(
                        physics: const AlwaysScrollableScrollPhysics(),
                        padding: const EdgeInsets.fromLTRB(
                          PeopleSpacing.page,
                          PeopleSpacing.xs,
                          PeopleSpacing.page,
                          PeopleSpacing.xl,
                        ),
                        itemCount: s.items.length + (s.hasMore ? 1 : 0),
                        separatorBuilder: (_, _) =>
                            const SizedBox(height: PeopleSpacing.xs),
                        itemBuilder: (context, i) {
                          if (i >= s.items.length) {
                            return const Padding(
                              padding: EdgeInsets.all(PeopleSpacing.md),
                              child: Center(
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                ),
                              ),
                            );
                          }
                          final p = s.items[i];
                          return PayslipCard(
                            payslip: p,
                            selected: wide && p.id == selectedId,
                            onTap: () {
                              if (wide) {
                                ref
                                    .read(selectedPayslipProvider.notifier)
                                    .set(p.id);
                              } else {
                                context.push(Routes.payslipDetail(p.id));
                              }
                            },
                          );
                        },
                      ),
                    ),
            ),
          ),
        ),
      ],
    );
  }
}

class _YearChip extends StatelessWidget {
  const _YearChip({
    required this.label,
    required this.selected,
    required this.onTap,
    this.isAll = false,
  });

  final bool isAll;
  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    return Padding(
      padding: const EdgeInsets.only(right: PeopleSpacing.xs),
      child: Semantics(
        container: true,
        button: true,
        selected: selected,
        label: isAll
            ? context.l10n.payslipYearAllSemantics
            : context.l10n.payslipYearSemantics(label),
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
                padding: const EdgeInsets.symmetric(
                  horizontal: 14,
                  vertical: 8,
                ),
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
      ),
    );
  }
}

class PayslipCard extends StatelessWidget {
  const PayslipCard({
    super.key,
    required this.payslip,
    required this.onTap,
    this.selected = false,
  });

  final Payslip payslip;
  final VoidCallback onTap;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final p = payslip;
    final l10n = context.l10n;
    final fmt = context.fmt;
    final periodName = payslipPeriodName(p, fmt);
    final amount = p.money(p.net, fmt.tag);
    return AppCard(
      shadow: false,
      onTap: onTap,
      color: selected ? c.primarySoft : null,
      borderColor: selected ? c.primary.withValues(alpha: 0.5) : null,
      semanticLabel: p.isPaid
          ? l10n.payslipCardSemanticsPaid(periodName, amount)
          : l10n.payslipCardSemanticsReleased(periodName, amount),
      child: Row(
        children: [
          const IconTile(icon: Icons.receipt_long_outlined, size: 44),
          const SizedBox(width: PeopleSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(periodName, style: t.titleSmall),
                const SizedBox(height: 2),
                Text(
                  amount,
                  style: t.titleMedium!.copyWith(fontWeight: FontWeight.w700),
                ),
                Text(
                  p.number,
                  style: t.labelSmall!.copyWith(color: c.textMuted),
                ),
              ],
            ),
          ),
          const SizedBox(width: PeopleSpacing.xs),
          payslipStatusBadge(context, p, dense: true),
          Icon(Icons.chevron_right_rounded, color: c.textMuted),
        ],
      ),
    );
  }
}
