import 'package:peopleos/core/network/api_client.dart';
import 'package:peopleos/core/network/read_through.dart';
import 'package:peopleos/core/storage/json_cache.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/core/utils/paged.dart';
import 'package:peopleos/features/payslip/domain/payslip.dart';

class ApiPayslipRepository implements PayslipRepository {
  ApiPayslipRepository({required this._client, required this._cache});

  final ApiClient _client;
  final JsonCache _cache;

  @override
  Future<Paged<Payslip>> list({int? year, int page = 1}) => readThrough(
    cache: _cache,
    key: 'payslips.${year ?? 'all'}.$page',
    fetch: () async {
      final res = await _client.get(
        '/payslips',
        query: {'scope': 'mine', 'page': page, 'per_page': 20, 'year': ?year},
      );
      return {'data': res.data, 'meta': res.meta};
    },
    parse: (raw, {required stale}) {
      final map = raw! as Map;
      final meta = (map['meta'] as Map?) ?? const {};
      return Paged(
        items: (map['data'] as List)
            .map((e) => Payslip.fromJson(JsonReader.of(e)))
            .toList(),
        page: (meta['page'] as num?)?.toInt() ?? page,
        lastPage: (meta['last_page'] as num?)?.toInt() ?? 1,
        total: (meta['total'] as num?)?.toInt(),
      );
    },
  );

  @override
  Future<Payslip> detail(int id) => readThrough(
    cache: _cache,
    key: 'payslips.detail.$id',
    fetch: () async => (await _client.get('/payslips/$id')).data,
    parse: (raw, {required stale}) => Payslip.fromJson(JsonReader.of(raw)),
  );

  @override
  Future<DownloadedFile> pdf(Payslip p) async {
    // Always through our own base URL so the token goes only to our server.
    final bytes = await _client.getBytes('/payslips/${p.id}/pdf');
    final name = p.number.isEmpty ? 'payslip-${p.id}' : p.number;
    return DownloadedFile(bytes: bytes, fileName: '$name.pdf');
  }
}
