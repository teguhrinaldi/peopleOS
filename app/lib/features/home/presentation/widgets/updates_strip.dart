import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:peopleos/app/router.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/widgets/app_card.dart';
import 'package:peopleos/core/widgets/list_widgets.dart';
import 'package:peopleos/features/documents/domain/document.dart';
import 'package:peopleos/features/documents/presentation/documents_providers.dart';
import 'package:peopleos/features/payslip/presentation/payslip_providers.dart';
import 'package:peopleos/l10n/l10n.dart';

/// "Updates": the latest released payslip and documents that need attention.
/// Renders nothing while loading, on error or when there is nothing to say.
class UpdatesStrip extends ConsumerWidget {
  const UpdatesStrip({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final payslip = ref.watch(latestPayslipProvider).value;
    final docs =
        ref.watch(attentionDocumentsProvider).value ??
        const <EmployeeDocument>[];
    if (payslip == null && docs.isEmpty) return const SizedBox.shrink();

    final c = context.colors;
    final l10n = context.l10n;
    final expired = docs
        .where((d) => d.status == DocumentStatus.expired)
        .length;
    final expiring = docs.length - expired;
    final docText = [
      if (expiring > 0) l10n.homeExpiringSoon(expiring),
      if (expired > 0) l10n.homeExpired(expired),
    ].join(' · ');

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SectionHeader(title: l10n.homeUpdates),
        const SizedBox(height: PeopleSpacing.xs),
        AppCard(
          shadow: false,
          padding: EdgeInsets.zero,
          child: Column(
            children: [
              if (payslip != null)
                AppListRow(
                  title: l10n.homePayslipAvailable,
                  subtitle:
                      '${payslip.period.name} · ${payslip.money(payslip.net, Localizations.localeOf(context).languageCode)}',
                  leading: const Icon(Icons.receipt_long_outlined),
                  onTap: () => context.push(Routes.payslipDetail(payslip.id)),
                ),
              if (payslip != null && docs.isNotEmpty) const RowDivider(),
              if (docs.isNotEmpty)
                AppListRow(
                  title: l10n.homeDocsNeedAttention(docs.length),
                  subtitle: docText,
                  leading: Icon(
                    expired > 0
                        ? Icons.error_outline_rounded
                        : Icons.schedule_rounded,
                    color: expired > 0 ? c.errorText : c.warningText,
                  ),
                  onTap: () => context.push(Routes.documents),
                ),
            ],
          ),
        ),
      ],
    );
  }
}
