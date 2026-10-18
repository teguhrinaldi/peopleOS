import 'dart:typed_data';

import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';

/// Attendance data access. Implemented by the Laravel API client and by the
/// in-memory Demo backend; the UI never knows which one is active.
abstract class AttendanceRepository {
  Future<TodayAttendance> today();

  /// All records with `work_date` in [from]..[to] (inclusive), newest first.
  Future<List<AttendanceRecord>> history({
    required DateTime from,
    required DateTime to,
  });

  Future<AttendanceRecord> detail(int id);

  /// Sends one recorded action. Must be safe to call repeatedly with the same
  /// [OutboxItem.idempotencyKey]. Throws [AppException] subtypes.
  Future<CheckResult> submit(OutboxItem item, {Uint8List? selfie});

  /// Selfie bytes for [AttendanceEvent.selfieUrl] (needs the bearer header).
  Future<Uint8List> selfieBytes(String selfieUrl);
}
