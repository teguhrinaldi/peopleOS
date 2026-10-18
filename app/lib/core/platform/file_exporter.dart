import 'package:peopleos/core/platform/file_exporter_io.dart'
    if (dart.library.js_interop) 'package:peopleos/core/platform/file_exporter_web.dart'
    as impl;
import 'package:peopleos/core/utils/paged.dart';

/// Hands a downloaded file to the user: the system share sheet (save, open
/// in another app, print) on phones and desktops, a browser download on web.
enum ExportKind { sharedSheet, downloaded }

/// What happened to an exported file; the UI turns it into localized text.
class ExportResult {
  const ExportResult(this.kind, this.fileName);
  final ExportKind kind;
  final String fileName;
}

abstract class FileExporter {
  /// Returns what happened (the caller shows a localized snackbar).
  Future<ExportResult> export(DownloadedFile file);
}

FileExporter createFileExporter() => impl.createPlatformExporter();
