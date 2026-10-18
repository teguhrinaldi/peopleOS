import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/core/utils/zoned_time.dart';
import 'package:peopleos/features/demo/demo_backend.dart';
import 'package:peopleos/features/requests/domain/request_models.dart';
import 'package:peopleos/features/requests/domain/requests_repository.dart';

class DemoRequestsRepository implements RequestsRepository {
  DemoRequestsRepository(this._backend);

  final DemoBackend _backend;

  CorrectionRequest _c(Map<String, dynamic> m) =>
      CorrectionRequest.fromJson(JsonReader.of(m));
  OvertimeRequest _o(Map<String, dynamic> m) =>
      OvertimeRequest.fromJson(JsonReader.of(m));

  @override
  Future<List<CorrectionRequest>> corrections() async =>
      (await _backend.corrections()).map(_c).toList();

  @override
  Future<CorrectionRequest> createCorrection({
    required DateTime workDate,
    ZonedTime? checkIn,
    ZonedTime? checkOut,
    required String reason,
  }) async => _c(
    await _backend.createCorrection(
      workDate: workDate,
      checkIn: checkIn,
      checkOut: checkOut,
      reason: reason,
    ),
  );

  @override
  Future<CorrectionRequest> cancelCorrection(int id) async =>
      _c(await _backend.cancelCorrection(id));

  @override
  Future<List<OvertimeRequest>> overtime() async =>
      (await _backend.overtime()).map(_o).toList();

  @override
  Future<OvertimeRequest> createOvertime({
    required DateTime workDate,
    required ZonedTime start,
    required ZonedTime end,
    required String reason,
  }) async => _o(
    await _backend.createOvertime(
      workDate: workDate,
      start: start,
      end: end,
      reason: reason,
    ),
  );

  @override
  Future<OvertimeRequest> cancelOvertime(int id) async =>
      _o(await _backend.cancelOvertime(id));
}
