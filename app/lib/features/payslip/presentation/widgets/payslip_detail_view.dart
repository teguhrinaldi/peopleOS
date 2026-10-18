import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/utils/money.dart';
import 'package:peopleos/core/widgets/app_card.dart';
import 'package:peopleos/core/widgets/download_button.dart';
import 'package:peopleos/core/widgets/skeleton.dart';
import 'package:peopleos/core/widgets/state_views.dart';
import 'package:peopleos/core/widgets/status_badge.dart';
import 'package:peopleos/features/payslip/domain/payslip.dart';
import 'package:peopleos/features/payslip/presentation/payslip_providers.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Period title that follows the app language ("August 2026" / "Agustus 2026"),
/// falling back to the server's own name when the period has no start date.
String payslipPeriodName(Payslip p, Formats fmt) => p.period.startDate == null
    ? p.period.name
    : fmt.monthYear(p.period.startDate!);

StatusBadge payslipStatusBadge(
  BuildContext context,
  Payslip p, {
  bool dense = false,
}) => p.isPaid
    ? StatusBadge(
        label: context.l10n.payslipStatusPaid,
        tone: BadgeTone.success,
        icon: Icons.check_circle_outline,
        dense: dense,
      )
    : StatusBadge(
        label: context.l10n.payslipStatusReleased,
        tone: BadgeTone.primary,
        icon: Icons.verified_outlined,
        dense: dense,
      );

/// Loads and renders one payslip. Used by the phone page and the tablet pane.
class PayslipDetailView extends ConsumerWidget {
  const PayslipDetailView({super.key, required this.id});

  final int id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(payslipDetailProvider(id));
    return detail.when(
      loading: () => const Column(
        children: [
          Skeleton(height: 120, radius: PeopleRadii.card),
          SizedBox(height: PeopleSpacing.md),
          Skeleton(height: 220, radius: PeopleRadii.card),
        ],
      ),
      error: (e, _) => SizedBox(
        height: 320,
        child: ErrorState(
          error: e,
          onRetry: () => ref.invalidate(payslipDetailProvider(id)),
        ),
      ),
      data: (p) => PayslipDetailBody(payslip: p),
    );
  }
}

class PayslipDetailBody extends ConsumerWidget {
  const PayslipDetailBody({super.key, required this.payslip});

  final Payslip payslip;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final p = payslip;
    final emp = p.employee;
    final inputs = p.inputs;
    final l10n = context.l10n;
    final fmt = context.fmt;
    final netText = p.money(p.net, fmt.tag);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        AppCard(
          padding: const EdgeInsets.all(PeopleSpacing.lg),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      l10n.payslipCaption,
                      style: t.labelMedium!.copyWith(letterSpacing: 1.4),
                    ),
                  ),
                  payslipStatusBadge(context, p),
                ],
              ),
              const SizedBox(height: 2),
              Semantics(
                header: true,
                child: Text(payslipPeriodName(p, fmt), style: t.headlineSmall),
              ),
              Text(p.number, style: t.bodySmall!.copyWith(color: c.textMuted)),
              if (p.companyName != null) ...[
                const SizedBox(height: PeopleSpacing.md),
                Text(p.companyName!, style: t.titleSmall),
                if (p.companyAddress != null)
                  Text(
                    p.companyAddress!,
                    style: t.bodySmall!.copyWith(color: c.textSecondary),
                  ),
              ],
              if (emp != null) ...[
                const SizedBox(height: PeopleSpacing.md),
                Text(emp.name, style: t.titleSmall),
                Text(
                  [
                    emp.number,
                    emp.position,
                    emp.department,
                  ].whereType<String>().join(' · '),
                  style: t.bodySmall!.copyWith(color: c.textSecondary),
                ),
              ],
              if (p.period.startDate != null && p.period.endDate != null) ...[
                const SizedBox(height: PeopleSpacing.xs),
                Text(
                  p.period.payDate == null
                      ? l10n.payslipPeriodRange(
                          fmt.shortDate(p.period.startDate!),
                          fmt.shortDate(p.period.endDate!),
                        )
                      : l10n.payslipPeriodRangePaid(
                          fmt.shortDate(p.period.startDate!),
                          fmt.shortDate(p.period.endDate!),
                          fmt.shortDate(p.period.payDate!),
                        ),
                  style: t.bodySmall!.copyWith(color: c.textMuted),
                ),
              ],
            ],
          ),
        ),
        if (inputs != null) ...[
          const SizedBox(height: PeopleSpacing.sm),
          _Inputs(inputs: inputs),
        ],
        const SizedBox(height: PeopleSpacing.md),
        _LinesCard(
          title: l10n.payslipEarnings,
          lines: p.earnings,
          totalLabel: l10n.payslipGrossPay,
          total: p.gross,
          payslip: p,
        ),
        const SizedBox(height: PeopleSpacing.sm),
        _LinesCard(
          title: l10n.payslipDeductions,
          lines: p.deductions,
          totalLabel: l10n.payslipTotalDeductions,
          total: p.deductionsTotal,
          payslip: p,
          emptyText: l10n.payslipNoDeductions,
        ),
        const SizedBox(height: PeopleSpacing.sm),
        Semantics(
          container: true,
          label: l10n.payslipNetPaySemantics(netText),
          excludeSemantics: true,
          child: Container(
            padding: const EdgeInsets.all(PeopleSpacing.md),
            decoration: BoxDecoration(
              color: c.primarySoft,
              borderRadius: PeopleRadii.cardRadius,
            ),
            child: Row(
              children: [
                Expanded(child: Text(l10n.payslipNetPay, style: t.titleMedium)),
                Text(
                  netText,
                  style: t.titleLarge!.copyWith(
                    color: c.primaryText,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),
        ),
        if (p.hasPdf) ...[
          const SizedBox(height: PeopleSpacing.lg),
          DownloadButton(
            label: l10n.payslipDownloadPdf,
            fetch: () => ref.read(payslipRepositoryProvider).pdf(p),
          ),
        ],
      ],
    );
  }
}

class _Inputs extends StatelessWidget {
  const _Inputs({required this.inputs});
  final PayslipInputs inputs;

  @override
  Widget build(BuildContext context) {
    final items = <(IconData, String)>[
      if (inputs.presentDays != null)
        (
          Icons.event_available_outlined,
          context.l10n.payslipInputsPresent(
            '${inputs.presentDays}',
            '${inputs.workingDays ?? '-'}',
          ),
        ),
      if (inputs.overtimeMinutes > 0)
        (
          Icons.more_time_rounded,
          context.l10n.payslipInputsOvertime(
            formatDurationL10n(context.l10n, inputs.overtimeMinutes),
          ),
        ),
      if (inputs.unpaidLeaveDays != '0.0' && inputs.unpaidLeaveDays != '0')
        (
          Icons.beach_access_outlined,
          context.l10n.payslipInputsUnpaidLeave(inputs.unpaidLeaveDays),
        ),
    ];
    if (items.isEmpty) return const SizedBox.shrink();
    final c = context.colors;
    return Wrap(
      spacing: PeopleSpacing.xs,
      runSpacing: PeopleSpacing.xs,
      children: [
        for (final (icon, text) in items)
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
            decoration: BoxDecoration(
              color: c.surfaceMuted,
              borderRadius: PeopleRadii.chipRadius,
              border: Border.all(color: c.border),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(icon, size: 15, color: c.textSecondary),
                const SizedBox(width: 6),
                Text(text, style: Theme.of(context).textTheme.labelMedium),
              ],
            ),
          ),
      ],
    );
  }
}

class _LinesCard extends StatelessWidget {
  const _LinesCard({
    required this.title,
    required this.lines,
    required this.totalLabel,
    required this.total,
    required this.payslip,
    this.emptyText,
  });

  final String title;
  final List<PayslipLine> lines;
  final String totalLabel;
  final Money total;
  final Payslip payslip;
  final String? emptyText;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    return AppCard(
      shadow: false,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(title, style: t.titleSmall),
          const SizedBox(height: PeopleSpacing.xs),
          if (lines.isEmpty && emptyText != null)
            Text(emptyText!, style: t.bodySmall!.copyWith(color: c.textMuted)),
          for (final l in lines)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(l.name, style: t.bodyMedium),
                        if (l.description != null && l.description!.isNotEmpty)
                          Text(
                            l.description!,
                            style: t.bodySmall!.copyWith(color: c.textMuted),
                          ),
                        if (l.adjustmentReason != null &&
                            l.adjustmentReason!.isNotEmpty)
                          Text(
                            context.l10n.payslipAdjustment(l.adjustmentReason!),
                            style: t.bodySmall!.copyWith(color: c.warningText),
                          ),
                      ],
                    ),
                  ),
                  const SizedBox(width: PeopleSpacing.sm),
                  Text(
                    payslip.money(l.amount, context.fmt.tag),
                    style: t.bodyMedium,
                  ),
                ],
              ),
            ),
          const Divider(height: PeopleSpacing.md),
          Row(
            children: [
              Expanded(child: Text(totalLabel, style: t.labelLarge)),
              Text(payslip.money(total, context.fmt.tag), style: t.labelLarge),
            ],
          ),
        ],
      ),
    );
  }
}
