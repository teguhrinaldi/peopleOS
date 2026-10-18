import 'dart:js_interop';
import 'dart:typed_data';

import 'package:peopleos/core/platform/file_exporter.dart';
import 'package:peopleos/core/utils/paged.dart';
import 'package:web/web.dart' as web;

FileExporter createPlatformExporter() => _WebFileExporter();

class _WebFileExporter implements FileExporter {
  @override
  Future<ExportResult> export(DownloadedFile file) async {
    final blob = web.Blob(
      [Uint8List.fromList(file.bytes).toJS].toJS,
      web.BlobPropertyBag(type: file.mimeType),
    );
    final url = web.URL.createObjectURL(blob);
    final anchor = web.HTMLAnchorElement()
      ..href = url
      ..download = file.fileName;
    web.document.body?.append(anchor);
    anchor.click();
    anchor.remove();
    web.URL.revokeObjectURL(url);
    return ExportResult(ExportKind.downloaded, file.fileName);
  }
}
