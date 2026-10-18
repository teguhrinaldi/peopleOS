import 'dart:io';
import 'dart:typed_data';

import 'package:path_provider/path_provider.dart';
import 'package:peopleos/core/platform/file_exporter.dart';
import 'package:peopleos/core/utils/paged.dart';
import 'package:share_plus/share_plus.dart';

FileExporter createPlatformExporter() => _IoFileExporter();

class _IoFileExporter implements FileExporter {
  @override
  Future<ExportResult> export(DownloadedFile file) async {
    final dir = await getTemporaryDirectory();
    final safe = file.fileName.replaceAll(RegExp(r'[^A-Za-z0-9._ -]'), '_');
    final saved = File('${dir.path}/$safe');
    await saved.writeAsBytes(Uint8List.fromList(file.bytes), flush: true);
    await SharePlus.instance.share(
      ShareParams(
        files: [XFile(saved.path, mimeType: file.mimeType, name: safe)],
        subject: file.fileName,
      ),
    );
    return ExportResult(ExportKind.sharedSheet, file.fileName);
  }
}
