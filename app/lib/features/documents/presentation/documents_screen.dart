import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:peopleos/app/router.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/utils/responsive.dart';
import 'package:peopleos/core/widgets/app_top_bar.dart';
import 'package:peopleos/core/widgets/master_detail.dart';
import 'package:peopleos/core/widgets/skeleton.dart';
import 'package:peopleos/core/widgets/state_views.dart';
import 'package:peopleos/features/documents/domain/document.dart';
import 'package:peopleos/features/documents/presentation/documents_providers.dart';
import 'package:peopleos/features/documents/presentation/widgets/document_widgets.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Profile > Documents: what the employee may see, filterable by type and
/// status. Tablets show the list and the selected document side by side.
class DocumentsScreen extends ConsumerWidget {
  const DocumentsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final wide = context.isWide;
    final selected = ref.watch(selectedDocumentProvider);
    const list = _DocumentList();
    return Scaffold(
      appBar: AppTopBar(title: context.l10n.documentsTitle),
      body: wide
          ? MasterDetail(
              master: list,
              emptyTitle: context.l10n.documentsSelectPrompt,
              emptyIcon: Icons.folder_open_outlined,
              detail: selected == null
                  ? null
                  : DocumentDetailView(key: ValueKey(selected), id: selected),
            )
          : const ContentWidth(maxWidth: 560, child: list),
    );
  }
}

class _DocumentList extends ConsumerWidget {
  const _DocumentList();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final wide = context.isWide;
    final state = ref.watch(documentsProvider);
    final controller = ref.read(documentsProvider.notifier);
    final filter = ref.watch(documentFilterProvider);
    final types =
        ref.watch(documentTypesProvider).value ?? const <DocumentType>[];
    final selected = ref.watch(selectedDocumentProvider);

    ref.listen(documentsProvider, (_, next) {
      final items = next.value?.items;
      if (wide && items != null && items.isNotEmpty && selected == null) {
        Future.microtask(
          () => ref.read(selectedDocumentProvider.notifier).set(items.first.id),
        );
      }
    });

    return Column(
      children: [
        _Filters(filter: filter, types: types),
        Expanded(
          child: state.when(
            skipLoadingOnRefresh: true,
            loading: () => const SkeletonList(itemHeight: 84),
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
                            icon: Icons.folder_open_outlined,
                            title: context.l10n.documentsEmptyTitle,
                            message:
                                filter.status != null || filter.typeId != null
                                ? context.l10n.documentsEmptyFiltered
                                : context.l10n.documentsEmptyDefault,
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
                          final d = s.items[i];
                          return DocumentCard(
                            document: d,
                            selected: wide && d.id == selected,
                            onTap: () {
                              if (wide) {
                                ref
                                    .read(selectedDocumentProvider.notifier)
                                    .set(d.id);
                              } else {
                                context.push(Routes.documentDetail(d.id));
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

class _Filters extends ConsumerWidget {
  const _Filters({required this.filter, required this.types});

  final DocumentFilter filter;
  final List<DocumentType> types;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final notifier = ref.read(documentFilterProvider.notifier);
    final statuses = [
      DocumentStatus.active,
      DocumentStatus.expiring,
      DocumentStatus.expired,
    ];
    return SizedBox(
      height: kMinTap + 4,
      child: ListView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: PeopleSpacing.page),
        children: [
          _Chip(
            label: context.l10n.documentsFilterAll,
            selected: filter.status == null && filter.typeId == null,
            onTap: () => notifier.set(const DocumentFilter()),
          ),
          for (final s in statuses)
            _Chip(
              label: s.label(context.l10n),
              selected: filter.status == s,
              onTap: () => notifier.set(filter.copyWith(status: s)),
            ),
          for (final t in types)
            _Chip(
              label: t.name,
              selected: filter.typeId == t.id,
              onTap: () => notifier.set(filter.copyWith(typeId: t.id)),
            ),
        ],
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
    return Padding(
      padding: const EdgeInsets.only(right: PeopleSpacing.xs),
      child: Semantics(
        container: true,
        button: true,
        selected: selected,
        label: context.l10n.documentsFilterSemantics(label),
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
