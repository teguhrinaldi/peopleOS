import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/widgets/app_card.dart';
import 'package:peopleos/core/widgets/download_button.dart';
import 'package:peopleos/core/widgets/list_widgets.dart';
import 'package:peopleos/core/widgets/skeleton.dart';
import 'package:peopleos/core/widgets/state_views.dart';
import 'package:peopleos/core/widgets/status_badge.dart';
import 'package:peopleos/features/documents/domain/document.dart';
import 'package:peopleos/features/documents/presentation/documents_providers.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Status as icon + text (never colour alone).
StatusBadge documentStatusBadge(
  BuildContext context,
  DocumentStatus s, {
  bool dense = false,
}) {
  return switch (s) {
    DocumentStatus.active => StatusBadge(
      label: s.label(context.l10n),
      tone: BadgeTone.success,
      icon: Icons.check_circle_outline,
      dense: dense,
    ),
    DocumentStatus.expiring => StatusBadge(
      label: s.label(context.l10n),
      tone: BadgeTone.warning,
      icon: Icons.schedule_rounded,
      dense: dense,
    ),
    DocumentStatus.expired => StatusBadge(
      label: s.label(context.l10n),
      tone: BadgeTone.error,
      icon: Icons.error_outline_rounded,
      dense: dense,
    ),
    DocumentStatus.archived => StatusBadge(
      label: s.label(context.l10n),
      tone: BadgeTone.neutral,
      icon: Icons.inventory_2_outlined,
      dense: dense,
    ),
  };
}

IconData documentIcon(EmployeeDocument d) =>
    switch (d.extension.toLowerCase()) {
      'png' || 'jpg' || 'jpeg' => Icons.image_outlined,
      'xlsx' => Icons.table_chart_outlined,
      'docx' => Icons.description_outlined,
      _ => Icons.picture_as_pdf_outlined,
    };

class DocumentCard extends StatelessWidget {
  const DocumentCard({
    super.key,
    required this.document,
    required this.onTap,
    this.selected = false,
  });

  final EmployeeDocument document;
  final VoidCallback onTap;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final d = document;
    final l10n = context.l10n;
    final expiry = d.expiryText(l10n);
    return AppCard(
      shadow: false,
      onTap: onTap,
      color: selected ? c.primarySoft : null,
      borderColor: selected ? c.primary.withValues(alpha: 0.5) : null,
      semanticLabel: expiry == null
          ? l10n.documentsSemantics(d.title, d.type.name, d.status.label(l10n))
          : l10n.documentsSemanticsExpiry(
              d.title,
              d.type.name,
              d.status.label(l10n),
              expiry,
            ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          IconTile(icon: documentIcon(d), size: 44),
          const SizedBox(width: PeopleSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  d.title,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: t.titleSmall,
                ),
                Text(
                  d.isCompany && d.type.code != 'COMPANY_DOCUMENT'
                      ? l10n.documentsTypeCompany(d.type.name)
                      : d.type.name,
                  style: t.bodySmall!.copyWith(color: c.textSecondary),
                ),
                if (expiry != null)
                  Text(
                    expiry,
                    style: t.bodySmall!.copyWith(
                      color: d.status == DocumentStatus.expired
                          ? c.errorText
                          : d.status == DocumentStatus.expiring
                          ? c.warningText
                          : c.textMuted,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(width: PeopleSpacing.xs),
          documentStatusBadge(context, d.status, dense: true),
        ],
      ),
    );
  }
}

/// Loads and renders one document with its download button. 404 and 403 are
/// shown as friendly messages.
class DocumentDetailView extends ConsumerWidget {
  const DocumentDetailView({super.key, required this.id});

  final int id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(documentDetailProvider(id));
    return detail.when(
      loading: () => const Column(
        children: [
          Skeleton(height: 140, radius: PeopleRadii.card),
          SizedBox(height: PeopleSpacing.md),
          Skeleton(height: 120, radius: PeopleRadii.card),
        ],
      ),
      error: (e, _) => SizedBox(
        height: 300,
        child: ErrorState(
          error: e,
          onRetry: () => ref.invalidate(documentDetailProvider(id)),
        ),
      ),
      data: (d) => DocumentDetailBody(document: d),
    );
  }
}

class DocumentDetailBody extends ConsumerWidget {
  const DocumentDetailBody({super.key, required this.document});

  final EmployeeDocument document;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final d = document;
    final l10n = context.l10n;
    final fmt = context.fmt;
    final expiry = d.expiryText(l10n);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        AppCard(
          padding: const EdgeInsets.all(PeopleSpacing.lg),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              IconTile(icon: documentIcon(d), size: 56, iconSize: 28),
              const SizedBox(width: PeopleSpacing.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Semantics(
                      header: true,
                      child: Text(d.title, style: t.titleMedium),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      d.type.name,
                      style: t.bodyMedium!.copyWith(color: c.textSecondary),
                    ),
                    const SizedBox(height: PeopleSpacing.xs),
                    Wrap(
                      spacing: PeopleSpacing.xs,
                      runSpacing: PeopleSpacing.xs,
                      children: [
                        documentStatusBadge(context, d.status),
                        if (expiry != null)
                          Text(
                            expiry,
                            style: t.labelMedium!.copyWith(
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: PeopleSpacing.sm),
        AppCard(
          shadow: false,
          padding: EdgeInsets.zero,
          child: Column(
            children: [
              KeyValueRow(
                label: l10n.documentsOwner,
                value: d.isCompany
                    ? l10n.documentsOwnerCompany
                    : l10n.documentsOwnerYou,
              ),
              const RowDivider(),
              KeyValueRow(
                label: l10n.documentsExpiryDate,
                value: d.expiryDate == null
                    ? l10n.documentsNoExpiry
                    : fmt.shortDate(d.expiryDate!),
              ),
              const RowDivider(),
              KeyValueRow(
                label: l10n.documentsFile,
                value: l10n.documentsFileValue(
                  d.fileName,
                  formatFileSize(l10n, d.sizeBytes),
                ),
              ),
              if (d.uploadedAt != null) ...[
                const RowDivider(),
                KeyValueRow(
                  label: l10n.documentsUploaded,
                  value: fmt.shortDate(d.uploadedAt!.wall),
                ),
              ],
            ],
          ),
        ),
        const SizedBox(height: PeopleSpacing.lg),
        DownloadButton(
          label: l10n.documentsDownloadOpen,
          fetch: () => ref.read(documentsRepositoryProvider).download(d),
        ),
      ],
    );
  }
}
