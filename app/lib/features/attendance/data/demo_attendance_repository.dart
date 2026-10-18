import 'dart:typed_data';

import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/features/attendance/domain/attendance_repository.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';
import 'package:peopleos/features/demo/demo_backend.dart';

class DemoAttendanceRepository implements AttendanceRepository {
  DemoAttendanceRepository(this._backend);

  final DemoBackend _backend;

  @override
  Future<TodayAttendance> today() async =>
      TodayAttendance.fromJson(JsonReader.of(await _backend.today()));

  @override
  Future<List<AttendanceRecord>> history({
    required DateTime from,
    required DateTime to,
  }) async => (await _backend.history(
    from,
    to,
  )).map((e) => AttendanceRecord.fromJson(JsonReader.of(e))).toList();

  @override
  Future<AttendanceRecord> detail(int id) async =>
      AttendanceRecord.fromJson(JsonReader.of(await _backend.detail(id)));

  @override
  Future<CheckResult> submit(OutboxItem item, {Uint8List? selfie}) async =>
      CheckResult.fromJson(JsonReader.of(await _backend.submit(item, selfie)));

  @override
  Future<Uint8List> selfieBytes(String selfieUrl) =>
      Future.error(const NotFoundException('Demo selfies are not stored.'));
}
