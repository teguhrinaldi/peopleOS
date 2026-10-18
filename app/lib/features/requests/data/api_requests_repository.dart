import 'package:peopleos/core/network/api_client.dart';
import 'package:peopleos/core/network/read_through.dart';
import 'package:peopleos/core/storage/json_cache.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/core/utils/zoned_time.dart';
import 'package:peopleos/features/requests/domain/request_models.dart';
import 'package:peopleos/features/requests/domain/requests_repository.dart';

class ApiRequestsRepository implements RequestsRepository {
  ApiRequestsRepository({required this._client, required this._cache});

  final ApiClient _client;
  final JsonCache _cache;

  @override
  Future<List<CorrectionRequest>> corrections() {
    return readThrough<List<CorrectionRequest>>(
      cache: _cache,
      key: 'requests.corrections',
      fetch: () =>
          _client.getAll('/attendance-corrections', query: {'scope': 'mine'}),
      parse: (raw, {required stale}) => (raw! as List)
          .map((e) => CorrectionRequest.fromJson(JsonReader.of(e)))
          .toList(),
    );
  }

  @override
  Future<CorrectionRequest> createCorrection({
    required DateTime workDate,
    ZonedTime? checkIn,
    ZonedTime? checkOut,
    required String reason,
  }) async {
    final res = await _client.post(
      '/attendance-corrections',
      body: {
        'work_date': formatDateOnly(workDate),
        if (checkIn != null) 'requested_check_in_at': checkIn.toIso(),
        if (checkOut != null) 'requested_check_out_at': checkOut.toIso(),
        'reason': reason,
      },
    );
    return CorrectionRequest.fromJson(JsonReader.of(res.data));
  }

  @override
  Future<CorrectionRequest> cancelCorrection(int id) async {
    final res = await _client.post('/attendance-corrections/$id/cancel');
    return CorrectionRequest.fromJson(JsonReader.of(res.data));
  }

  @override
  Future<List<OvertimeRequest>> overtime() {
    return readThrough<List<OvertimeRequest>>(
      cache: _cache,
      key: 'requests.overtime',
      fetch: () =>
          _client.getAll('/overtime-requests', query: {'scope': 'mine'}),
      parse: (raw, {required stale}) => (raw! as List)
          .map((e) => OvertimeRequest.fromJson(JsonReader.of(e)))
          .toList(),
    );
  }

  @override
  Future<OvertimeRequest> createOvertime({
    required DateTime workDate,
    required ZonedTime start,
    required ZonedTime end,
    required String reason,
  }) async {
    final res = await _client.post(
      '/overtime-requests',
      body: {
        'work_date': formatDateOnly(workDate),
        'start_at': start.toIso(),
        'end_at': end.toIso(),
        'reason': reason,
      },
    );
    return OvertimeRequest.fromJson(JsonReader.of(res.data));
  }

  @override
  Future<OvertimeRequest> cancelOvertime(int id) async {
    final res = await _client.post('/overtime-requests/$id/cancel');
    return OvertimeRequest.fromJson(JsonReader.of(res.data));
  }
}
