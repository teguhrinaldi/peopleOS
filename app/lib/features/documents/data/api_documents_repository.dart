import 'package:peopleos/core/config/app_config.dart';
import 'package:peopleos/core/network/api_client.dart';
import 'package:peopleos/core/network/read_through.dart';
import 'package:peopleos/core/storage/json_cache.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/core/utils/paged.dart';
import 'package:peopleos/features/documents/domain/document.dart';

class ApiDocumentsRepository implements DocumentsRepository {
  ApiDocumentsRepository({required this._client, required this._cache});

  final ApiClient _client;
  final JsonCache _cache;

  @override
  Future<List<DocumentType>> types() => readThrough(
    cache: _cache,
    key: 'documents.types',
    fetch: () async => (await _client.get('/document-types')).data,
    parse: (raw, {required stale}) => (raw! as List)
        .map((e) => DocumentType.fromJson(JsonReader.of(e)))
        .toList(),
  );

  @override
  Future<Paged<EmployeeDocument>> list({
    int? typeId,
    DocumentStatus? status,
    int page = 1,
  }) => readThrough(
    cache: _cache,
    key: 'documents.${typeId ?? 'all'}.${status?.wire ?? 'all'}.$page',
    fetch: () async {
      final res = await _client.get(
        '/documents',
        query: {
          'scope': 'mine',
          'page': page,
          'per_page': 20,
          'document_type_id': ?typeId,
          'status': ?status?.wire.toLowerCase(),
        },
      );
      return {'data': res.data, 'meta': res.meta};
    },
    parse: (raw, {required stale}) {
      final map = raw! as Map;
      final meta = (map['meta'] as Map?) ?? const {};
      return Paged(
        items: (map['data'] as List)
            .map((e) => EmployeeDocument.fromJson(JsonReader.of(e)))
            .toList(),
        page: (meta['page'] as num?)?.toInt() ?? page,
        lastPage: (meta['last_page'] as num?)?.toInt() ?? 1,
        total: (meta['total'] as num?)?.toInt(),
      );
    },
  );

  @override
  Future<EmployeeDocument> detail(int id) async => EmployeeDocument.fromJson(
    JsonReader.of((await _client.get('/documents/$id')).data),
  );

  @override
  Future<DownloadedFile> download(EmployeeDocument doc) async {
    // Strip the API prefix and re-resolve against the configured server: the
    // bearer token must only ever go to our own backend.
    final i = doc.downloadUrl.indexOf(AppConfig.apiPrefix);
    final path = i >= 0
        ? doc.downloadUrl.substring(i + AppConfig.apiPrefix.length)
        : '/documents/${doc.id}/download';
    final bytes = await _client.getBytes(path);
    return DownloadedFile(
      bytes: bytes,
      fileName: doc.fileName,
      mimeType: _mime(doc.extension),
    );
  }

  static String _mime(String ext) => switch (ext.toLowerCase()) {
    'pdf' => 'application/pdf',
    'png' => 'image/png',
    'jpg' || 'jpeg' => 'image/jpeg',
    'docx' =>
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xlsx' =>
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    _ => 'application/octet-stream',
  };
}
