import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/core/utils/paged.dart';
import 'package:peopleos/core/utils/paged_controller.dart';
import 'package:peopleos/features/auth/presentation/auth_controller.dart';
import 'package:peopleos/features/documents/domain/document.dart';

@immutable
class DocumentFilter {
  const DocumentFilter({this.typeId, this.status});

  final int? typeId;
  final DocumentStatus? status;

  DocumentFilter copyWith({
    int? typeId,
    DocumentStatus? status,
    bool clearType = false,
    bool clearStatus = false,
  }) => DocumentFilter(
    typeId: clearType ? null : (typeId ?? this.typeId),
    status: clearStatus ? null : (status ?? this.status),
  );
}

class DocumentFilterNotifier extends Notifier<DocumentFilter> {
  @override
  DocumentFilter build() => const DocumentFilter();

  void set(DocumentFilter f) => state = f;
}

final documentFilterProvider =
    NotifierProvider<DocumentFilterNotifier, DocumentFilter>(
      DocumentFilterNotifier.new,
    );

class DocumentsController extends PagedNotifier<EmployeeDocument> {
  @override
  Future<Paged<EmployeeDocument>> fetch(int page) {
    final f = ref.read(documentFilterProvider);
    return ref
        .read(documentsRepositoryProvider)
        .list(typeId: f.typeId, status: f.status, page: page);
  }

  @override
  Future<PagedState<EmployeeDocument>> build() {
    ref.watch(documentFilterProvider);
    ref.watch(employeeIdProvider);
    return super.build();
  }
}

final documentsProvider =
    AsyncNotifierProvider<DocumentsController, PagedState<EmployeeDocument>>(
      DocumentsController.new,
    );

final documentTypesProvider = FutureProvider<List<DocumentType>>((ref) {
  ref.watch(employeeIdProvider);
  return ref.watch(documentsRepositoryProvider).types();
});

final documentDetailProvider = FutureProvider.autoDispose
    .family<EmployeeDocument, int>(
      (ref, id) => ref.watch(documentsRepositoryProvider).detail(id),
    );

/// Documents that are EXPIRING or EXPIRED (for the Home updates strip).
final attentionDocumentsProvider = FutureProvider<List<EmployeeDocument>>((
  ref,
) async {
  ref.watch(employeeIdProvider);
  final repo = ref.watch(documentsRepositoryProvider);
  final expiring = await repo.list(status: DocumentStatus.expiring);
  final expired = await repo.list(status: DocumentStatus.expired);
  return [...expired.items, ...expiring.items];
});

class SelectedDocumentNotifier extends Notifier<int?> {
  @override
  int? build() => null;

  void set(int? id) => state = id;
}

final selectedDocumentProvider =
    NotifierProvider<SelectedDocumentNotifier, int?>(
      SelectedDocumentNotifier.new,
    );
