import 'package:flutter/foundation.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/core/utils/paged.dart';
import 'package:intl/intl.dart';
import 'package:peopleos/core/utils/zoned_time.dart';
import 'package:peopleos/l10n/generated/app_localizations.dart';

enum DocumentStatus {
  active('ACTIVE'),
  expiring('EXPIRING'),
  expired('EXPIRED'),
  archived('ARCHIVED');

  const DocumentStatus(this.wire);
  final String wire;

  String label(AppLocalizations l10n) => switch (this) {
    active => l10n.documentsStatusActive,
    expiring => l10n.documentsStatusExpiring,
    expired => l10n.documentsStatusExpired,
    archived => l10n.documentsStatusArchived,
  };

  static DocumentStatus parse(String? raw) => DocumentStatus.values.firstWhere(
    (s) => s.wire == raw?.toUpperCase(),
    orElse: () => DocumentStatus.active,
  );
}

@immutable
class DocumentType {
  const DocumentType({
    required this.id,
    required this.code,
    required this.name,
  });

  final int id;
  final String code;
  final String name;

  factory DocumentType.fromJson(JsonReader j) => DocumentType(
    id: j.intOrNull('id') ?? 0,
    code: j.str('code') ?? '',
    name: j.str('name') ?? 'Document',
  );
}

@immutable
class EmployeeDocument {
  const EmployeeDocument({
    required this.id,
    required this.title,
    required this.type,
    required this.status,
    required this.isCompany,
    required this.fileName,
    required this.extension,
    required this.sizeBytes,
    required this.downloadUrl,
    this.expiryDate,
    this.daysUntilExpiry,
    this.uploadedAt,
  });

  final int id;
  final String title;
  final DocumentType type;
  final DocumentStatus status;
  final bool isCompany;
  final DateTime? expiryDate;

  /// null = no expiry; 0 = last valid day; negative = expired.
  final int? daysUntilExpiry;
  final String fileName;
  final String extension;
  final int sizeBytes;

  /// API path (`/api/v1/documents/{id}/download`). Only ever fetched through
  /// the authenticated client; storage paths are never exposed.
  final String downloadUrl;
  final ZonedTime? uploadedAt;

  /// Plain-language expiry text; always shown next to the status icon.
  String? expiryText(AppLocalizations l10n) {
    final d = daysUntilExpiry;
    if (d == null || status == DocumentStatus.archived) return null;
    if (d < 0) {
      return l10n.documentsExpiredAgo(-d);
    }
    if (d == 0) return l10n.documentsExpiresToday;
    if (d == 1) return l10n.documentsExpiresTomorrow;
    return l10n.documentsExpiresIn(d);
  }

  factory EmployeeDocument.fromJson(JsonReader j) {
    final file = j.obj('file');
    return EmployeeDocument(
      id: j.intOrNull('id') ?? 0,
      title: j.str('title') ?? 'Document',
      type: DocumentType.fromJson(
        j.obj('document_type') ?? const JsonReader({}),
      ),
      status: DocumentStatus.parse(j.str('status')),
      isCompany: j.boolOr('is_company_document', false),
      expiryDate: j.date('expiry_date'),
      daysUntilExpiry: j.intOrNull('days_until_expiry'),
      fileName: file?.str('name') ?? 'document',
      extension: file?.str('extension') ?? '',
      sizeBytes: file?.intOr('size_bytes', 0) ?? 0,
      downloadUrl: j.str('download_url') ?? '',
      uploadedAt: j.time('uploaded_at'),
    );
  }
}

String formatFileSize(AppLocalizations l10n, int bytes) {
  if (bytes < 1024) return l10n.documentsFileSizeBytes(bytes);
  if (bytes < 1024 * 1024) {
    return l10n.documentsFileSizeKb((bytes / 1024).toStringAsFixed(0));
  }
  final mb = NumberFormat.decimalPatternDigits(
    locale: l10n.localeName,
    decimalDigits: 1,
  ).format(bytes / 1024 / 1024);
  return l10n.documentsFileSizeMb(mb);
}

abstract class DocumentsRepository {
  Future<List<DocumentType>> types();

  Future<Paged<EmployeeDocument>> list({
    int? typeId,
    DocumentStatus? status,
    int page = 1,
  });

  Future<EmployeeDocument> detail(int id);

  Future<DownloadedFile> download(EmployeeDocument doc);
}
