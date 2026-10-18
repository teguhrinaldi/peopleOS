import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/platform/file_exporter.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/core/utils/paged.dart';
import 'package:peopleos/features/documents/domain/document.dart';
import 'package:peopleos/features/payslip/domain/payslip.dart';

import 'batch2_json.dart';

class FakePayslipRepository implements PayslipRepository {
  FakePayslipRepository({List<Payslip>? items}) : items = items ?? [];

  List<Payslip> items;
  Object? listError;
  Object? pdfError;
  final List<int> pdfRequests = [];
  final List<int?> yearRequests = [];

  static Payslip sample({
    int id = 22,
    String code = '2026-08',
    String name = 'August 2026',
  }) => Payslip.fromJson(
    JsonReader(
      payslipSummaryJson(
        id: id,
        code: code,
        name: name,
        number: 'PS-${code.replaceAll('-', '')}-0006',
      ),
    ),
  );

  @override
  Future<Paged<Payslip>> list({int? year, int page = 1}) async {
    yearRequests.add(year);
    if (listError != null) throw listError!;
    final filtered = items
        .where((p) => year == null || p.period.year == year)
        .toList();
    return Paged(items: filtered, total: filtered.length);
  }

  @override
  Future<Payslip> detail(int id) async =>
      Payslip.fromJson(JsonReader(payslipDetailJson(id: id)));

  @override
  Future<DownloadedFile> pdf(Payslip payslip) async {
    pdfRequests.add(payslip.id);
    if (pdfError != null) throw pdfError!;
    return DownloadedFile(
      bytes: [0x25, 0x50, 0x44, 0x46],
      fileName: '${payslip.number}.pdf',
    );
  }
}

class FakeDocumentsRepository implements DocumentsRepository {
  List<EmployeeDocument> items = [];
  Object? listError;
  Object? downloadError;
  final List<int> downloads = [];

  static EmployeeDocument sample(Map<String, dynamic> json) =>
      EmployeeDocument.fromJson(JsonReader(json));

  @override
  Future<List<DocumentType>> types() async => const [
    DocumentType(
      id: 2,
      code: 'IDENTIFICATION',
      name: 'Identification Reference',
    ),
  ];

  @override
  Future<Paged<EmployeeDocument>> list({
    int? typeId,
    DocumentStatus? status,
    int page = 1,
  }) async {
    if (listError != null) throw listError!;
    final f = items.where((d) => status == null || d.status == status).toList();
    return Paged(items: f, total: f.length);
  }

  @override
  Future<EmployeeDocument> detail(int id) async => items.firstWhere(
    (d) => d.id == id,
    orElse: () => throw const NotFoundException('missing', code: 'NOT_FOUND'),
  );

  @override
  Future<DownloadedFile> download(EmployeeDocument doc) async {
    downloads.add(doc.id);
    if (downloadError != null) throw downloadError!;
    return DownloadedFile(bytes: [1, 2, 3], fileName: doc.fileName);
  }
}

/// Records what would have been shared or downloaded.
class FakeFileExporter implements FileExporter {
  final List<DownloadedFile> exported = [];

  @override
  Future<ExportResult> export(DownloadedFile file) async {
    exported.add(file);
    return ExportResult(ExportKind.downloaded, file.fileName);
  }
}
