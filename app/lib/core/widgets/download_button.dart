import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/utils/paged.dart';
import 'package:peopleos/core/widgets/buttons.dart';
import 'package:peopleos/core/widgets/sheets_dialogs.dart';
import 'package:peopleos/core/platform/file_exporter.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Downloads a private file with the bearer header ([fetch]) and hands it to
/// the platform (share sheet / browser download). Shows progress and maps
/// failures (403, 404, offline ...) to a clear message.
class DownloadButton extends ConsumerStatefulWidget {
  const DownloadButton({
    super.key,
    required this.label,
    required this.fetch,
    this.outline = false,
  });

  final String label;
  final Future<DownloadedFile> Function() fetch;
  final bool outline;

  @override
  ConsumerState<DownloadButton> createState() => _DownloadButtonState();
}

class _DownloadButtonState extends ConsumerState<DownloadButton> {
  bool _busy = false;

  Future<void> _run() async {
    if (_busy) return;
    setState(() => _busy = true);
    final l10n = context.l10n;
    String? message;
    var failed = false;
    try {
      final file = await widget.fetch();
      final r = await ref.read(fileExporterProvider).export(file);
      message = switch (r.kind) {
        ExportKind.sharedSheet => l10n.commonSharedFile(r.fileName),
        ExportKind.downloaded => l10n.commonDownloadedFile(r.fileName),
      };
    } on NotFoundException catch (e) {
      failed = true;
      message = e.code == 'FILE_MISSING'
          ? l10n.errFileMissing
          : l10n.errFileUnavailable;
    } on ForbiddenException {
      failed = true;
      message = l10n.errFileForbidden;
    } on AppException catch (e) {
      failed = true;
      message = localizedErrorMessage(e, l10n);
    } on Object {
      failed = true;
      message = l10n.errFileOpenFailed;
    }
    if (!mounted) return;
    setState(() => _busy = false);
    showAppSnack(context, message, error: failed);
  }

  @override
  Widget build(BuildContext context) {
    return widget.outline
        ? OutlineButton(
            label: widget.label,
            icon: Icons.download_rounded,
            loading: _busy,
            onPressed: _run,
          )
        : PrimaryButton(
            label: widget.label,
            icon: Icons.download_rounded,
            loading: _busy,
            onPressed: _run,
          );
  }
}
