import 'package:peopleos/core/utils/zoned_time.dart';
import 'package:peopleos/features/requests/domain/request_models.dart';

abstract class RequestsRepository {
  Future<List<CorrectionRequest>> corrections();

  /// At least one of [checkIn] / [checkOut] is required by the server.
  Future<CorrectionRequest> createCorrection({
    required DateTime workDate,
    ZonedTime? checkIn,
    ZonedTime? checkOut,
    required String reason,
  });

  Future<CorrectionRequest> cancelCorrection(int id);

  Future<List<OvertimeRequest>> overtime();

  Future<OvertimeRequest> createOvertime({
    required DateTime workDate,
    required ZonedTime start,
    required ZonedTime end,
    required String reason,
  });

  Future<OvertimeRequest> cancelOvertime(int id);
}
