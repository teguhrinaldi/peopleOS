import 'package:peopleos/core/network/api_client.dart';
import 'package:peopleos/core/network/read_through.dart';
import 'package:peopleos/core/storage/json_cache.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/features/leave/domain/leave_models.dart';
import 'package:peopleos/features/leave/domain/leave_repository.dart';

class ApiLeaveRepository implements LeaveRepository {
  ApiLeaveRepository({required this._client, required this._cache});

  final ApiClient _client;
  final JsonCache _cache;

  List<T> _list<T>(Object? raw, T Function(JsonReader) f) =>
      (raw! as List).map((e) => f(JsonReader.of(e))).toList();

  @override
  Future<List<LeaveType>> types() => readThrough(
    cache: _cache,
    key: 'leave.types',
    fetch: () async => (await _client.get('/leave-types')).data,
    parse: (raw, {required stale}) => _list(raw, LeaveType.fromJson),
  );

  @override
  Future<List<LeaveBalance>> balances({int? year}) => readThrough(
    cache: _cache,
    key: 'leave.balances.${year ?? 'current'}',
    fetch: () async =>
        (await _client.get('/leave-balances', query: {'year': ?year})).data,
    parse: (raw, {required stale}) => _list(raw, LeaveBalance.fromJson),
  );

  @override
  Future<List<LeaveRequest>> requests({LeaveStatus? status}) => readThrough(
    cache: _cache,
    key: 'leave.requests.${status?.wire ?? 'all'}',
    fetch: () => _client.getAll(
      '/leave-requests',
      query: {'scope': 'mine', 'status': ?status?.wire},
    ),
    parse: (raw, {required stale}) => _list(raw, LeaveRequest.fromJson),
  );

  @override
  Future<LeaveRequest> detail(int id) async => LeaveRequest.fromJson(
    JsonReader.of((await _client.get('/leave-requests/$id')).data),
  );

  @override
  Future<LeavePreview> preview(LeaveDraft draft) async => LeavePreview.fromJson(
    JsonReader.of(
      (await _client.post(
        '/leave-requests/preview',
        body: draft.toJson(),
      )).data,
    ),
  );

  @override
  Future<LeaveRequest> create(LeaveDraft draft, {required bool submit}) async =>
      _request(
        await _client.post(
          '/leave-requests',
          body: {...draft.toJson(), 'submit': submit},
        ),
      );

  @override
  Future<LeaveRequest> update(int id, LeaveDraft draft) async => _request(
    await _client.patch('/leave-requests/$id', body: draft.toJson()),
  );

  @override
  Future<LeaveRequest> submit(int id) async =>
      _request(await _client.post('/leave-requests/$id/submit'));

  @override
  Future<LeaveRequest> cancel(int id, {String? reason}) async => _request(
    await _client.post(
      '/leave-requests/$id/cancel',
      body: {if (reason != null && reason.isNotEmpty) 'reason': reason},
    ),
  );

  LeaveRequest _request(ApiResponse res) =>
      LeaveRequest.fromJson(JsonReader.of(res.data));
}
