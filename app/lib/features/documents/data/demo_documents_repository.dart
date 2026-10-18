import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/core/utils/paged.dart';
import 'package:peopleos/features/demo/demo_backend.dart';
import 'package:peopleos/features/documents/domain/document.dart';

class DemoDocumentsRepository implements DocumentsRepository {
  DemoDocumentsRepository(this._backend);

  final DemoBackend _backend;

  @override
  Future<List<DocumentType>> types() async => (await _backend.documentTypes())
      .map((m) => DocumentType.fromJson(JsonReader(m)))
      .toList();

  @override
  Future<Paged<EmployeeDocument>> list({
    int? typeId,
    DocumentStatus? status,
    int page = 1,
  }) async {
    final res = await _backend.documents(
      typeId: typeId,
      status: status?.wire,
      page: page,
    );
    return Paged(
      items: (res['data'] as List)
          .map((e) => EmployeeDocument.fromJson(JsonReader.of(e)))
          .toList(),
      page: res['page'] as int,
      lastPage: res['last_page'] as int,
      total: res['total'] as int,
    );
  }

  @override
  Future<EmployeeDocument> detail(int id) async => EmployeeDocument.fromJson(
    JsonReader.of(await _backend.documentDetail(id)),
  );

  @override
  Future<DownloadedFile> download(EmployeeDocument doc) async => DownloadedFile(
    bytes: await _backend.documentBytes(doc.id),
    fileName: '${doc.fileName.replaceAll(RegExp(r'\.pdf$'), '')}.pdf',
  );
}
