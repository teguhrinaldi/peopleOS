import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/features/auth/presentation/auth_controller.dart';
import 'package:peopleos/features/leave/domain/leave_models.dart';

final leaveTypesProvider = FutureProvider<List<LeaveType>>((ref) {
  ref.watch(employeeIdProvider);
  return ref.watch(leaveRepositoryProvider).types();
});

/// Balances of the current leave year.
final leaveBalancesProvider = FutureProvider<List<LeaveBalance>>((ref) {
  ref.watch(employeeIdProvider);
  return ref.watch(leaveRepositoryProvider).balances();
});

/// All own requests (drafts included); filtering happens in the UI.
final leaveRequestsProvider = FutureProvider<List<LeaveRequest>>((ref) {
  ref.watch(employeeIdProvider);
  return ref.watch(leaveRepositoryProvider).requests();
});

final leaveDetailProvider = FutureProvider.autoDispose
    .family<LeaveRequest, int>(
      (ref, id) => ref.watch(leaveRepositoryProvider).detail(id),
    );

/// Status filter of the leave list (null = all).
class LeaveFilterNotifier extends Notifier<LeaveStatus?> {
  @override
  LeaveStatus? build() => null;

  void set(LeaveStatus? status) => state = status;
}

final leaveFilterProvider = NotifierProvider<LeaveFilterNotifier, LeaveStatus?>(
  LeaveFilterNotifier.new,
);

/// After any write: balances, lists and details must be refetched.
void invalidateLeave(WidgetRef ref) {
  ref
    ..invalidate(leaveBalancesProvider)
    ..invalidate(leaveRequestsProvider)
    ..invalidate(leaveDetailProvider);
}
