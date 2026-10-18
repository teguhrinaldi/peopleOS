import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/features/attendance/domain/effective_today.dart';
import 'package:peopleos/features/attendance/presentation/outbox_controller.dart';
import 'package:peopleos/features/auth/presentation/auth_controller.dart';

@immutable
class TodayLoad {
  const TodayLoad(this.today, this.fetchedAtUtc);
  final TodayAttendance today;
  final DateTime fetchedAtUtc;
}

/// `GET /attendance/today` (or the cached copy when offline).
final serverTodayProvider = FutureProvider<TodayLoad>((ref) async {
  ref.watch(employeeIdProvider);
  final today = await ref.watch(attendanceRepositoryProvider).today();
  return TodayLoad(today, DateTime.now().toUtc());
});

/// The Home read model: server state + unsynced local actions.
final effectiveTodayProvider = Provider<AsyncValue<EffectiveToday>>((ref) {
  final server = ref.watch(serverTodayProvider);
  final outbox = ref.watch(outboxControllerProvider);
  final employeeId = ref.watch(employeeIdProvider);
  return server.whenData(
    (load) => mergeToday(
      server: load.today,
      items: outbox.items,
      employeeId: employeeId ?? -1,
      fetchedAtUtc: load.fetchedAtUtc,
    ),
  );
});

class SelectedMonthNotifier extends Notifier<DateTime> {
  @override
  DateTime build() {
    final now = DateTime.now();
    return DateTime(now.year, now.month);
  }

  void shift(int months) => state = DateTime(state.year, state.month + months);

  bool get isCurrentMonth {
    final now = DateTime.now();
    return state.year == now.year && state.month == now.month;
  }
}

final selectedMonthProvider = NotifierProvider<SelectedMonthNotifier, DateTime>(
  SelectedMonthNotifier.new,
);

/// Records of one month (key: first day of the month).
final attendanceHistoryProvider =
    FutureProvider.family<List<AttendanceRecord>, DateTime>((ref, month) {
      ref.watch(employeeIdProvider);
      final last = DateTime(month.year, month.month + 1, 0);
      return ref
          .watch(attendanceRepositoryProvider)
          .history(from: month, to: last);
    });
