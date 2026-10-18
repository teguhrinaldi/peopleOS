import 'package:peopleos/features/leave/domain/leave_models.dart';

/// Leave data access (API and Demo implementations). Throws AppException.
abstract class LeaveRepository {
  Future<List<LeaveType>> types();

  Future<List<LeaveBalance>> balances({int? year});

  /// Own requests (drafts included), newest first.
  Future<List<LeaveRequest>> requests({LeaveStatus? status});

  Future<LeaveRequest> detail(int id);

  Future<LeavePreview> preview(LeaveDraft draft);

  /// [submit] false keeps it as a DRAFT (no balance reserved).
  Future<LeaveRequest> create(LeaveDraft draft, {required bool submit});

  /// Drafts only.
  Future<LeaveRequest> update(int id, LeaveDraft draft);

  Future<LeaveRequest> submit(int id);

  Future<LeaveRequest> cancel(int id, {String? reason});
}
