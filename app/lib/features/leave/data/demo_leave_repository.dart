import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/features/demo/demo_backend.dart';
import 'package:peopleos/features/leave/domain/leave_models.dart';
import 'package:peopleos/features/leave/domain/leave_repository.dart';

class DemoLeaveRepository implements LeaveRepository {
  DemoLeaveRepository(this._backend);

  final DemoBackend _backend;

  LeaveRequest _r(Map<String, dynamic> m) =>
      LeaveRequest.fromJson(JsonReader(m));

  @override
  Future<List<LeaveType>> types() async => (await _backend.leaveTypes())
      .map((m) => LeaveType.fromJson(JsonReader(m)))
      .toList();

  @override
  Future<List<LeaveBalance>> balances({int? year}) async =>
      (await _backend.leaveBalances(year))
          .map((m) => LeaveBalance.fromJson(JsonReader(m)))
          .toList();

  @override
  Future<List<LeaveRequest>> requests({LeaveStatus? status}) async =>
      (await _backend.leaveRequests(status?.wire)).map(_r).toList();

  @override
  Future<LeaveRequest> detail(int id) async =>
      _r(await _backend.leaveDetail(id));

  @override
  Future<LeavePreview> preview(LeaveDraft draft) async => LeavePreview.fromJson(
    JsonReader(await _backend.leavePreview(draft.toJson())),
  );

  @override
  Future<LeaveRequest> create(LeaveDraft draft, {required bool submit}) async =>
      _r(await _backend.leaveCreate(draft.toJson(), submit));

  @override
  Future<LeaveRequest> update(int id, LeaveDraft draft) async =>
      _r(await _backend.leaveUpdate(id, draft.toJson()));

  @override
  Future<LeaveRequest> submit(int id) async =>
      _r(await _backend.leaveSubmit(id));

  @override
  Future<LeaveRequest> cancel(int id, {String? reason}) async =>
      _r(await _backend.leaveCancel(id, reason));
}
