import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:peopleos/core/config/app_config.dart';
import 'package:peopleos/core/network/api_client.dart';
import 'package:peopleos/core/network/read_through.dart';
import 'package:peopleos/core/storage/json_cache.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/core/utils/zoned_time.dart';
import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/features/attendance/domain/attendance_repository.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';

class ApiAttendanceRepository implements AttendanceRepository {
  ApiAttendanceRepository({
    required this._client,
    required this._cache,
    this.appVersion = '1.0.0',
    DateTime Function()? clock,
  }) : _clock = clock ?? DateTime.now;

  final ApiClient _client;
  final JsonCache _cache;
  final String appVersion;
  final DateTime Function() _clock;

  @override
  Future<TodayAttendance> today() {
    return readThrough<TodayAttendance>(
      cache: _cache,
      key: 'attendance.today',
      fetch: () async => (await _client.get('/attendance/today')).data,
      parse: (raw, {required stale}) {
        final today = TodayAttendance.fromJson(JsonReader.of(raw));
        return stale ? today.asStaleAt(_clock().toUtc()) : today;
      },
    );
  }

  @override
  Future<List<AttendanceRecord>> history({
    required DateTime from,
    required DateTime to,
  }) {
    final f = formatDateOnly(from);
    final t = formatDateOnly(to);
    return readThrough<List<AttendanceRecord>>(
      cache: _cache,
      key: 'attendance.history.$f.$t',
      fetch: () => _client.getAll('/attendance', query: {'from': f, 'to': t}),
      parse: (raw, {required stale}) =>
          (raw! as List)
              .map((e) => AttendanceRecord.fromJson(JsonReader.of(e)))
              .toList()
            ..sort((a, b) => b.workDate.compareTo(a.workDate)),
    );
  }

  @override
  Future<AttendanceRecord> detail(int id) async {
    final res = await _client.get('/attendance/$id');
    return AttendanceRecord.fromJson(JsonReader.of(res.data));
  }

  @override
  Future<CheckResult> submit(OutboxItem item, {Uint8List? selfie}) async {
    final fields = <String, dynamic>{
      'idempotency_key': item.idempotencyKey,
      'latitude': ?item.latitude,
      'longitude': ?item.longitude,
      'accuracy_meters': ?item.accuracyMeters,
      if (item.locationCapturedAt != null)
        'location_captured_at': item.locationCapturedAt!
            .toUtc()
            .toIso8601String(),
      'device_captured_at': item.deviceCapturedAt.toUtc().toIso8601String(),
      'is_offline_sync': item.isOfflineSync,
      'is_mock_location': item.isMockLocation,
      'device_platform': kIsWeb ? 'web' : defaultTargetPlatform.name,
      'app_version': appVersion,
    };

    final Object body;
    if (selfie != null) {
      // Multipart form values are strings; Laravel's boolean rule accepts 1/0.
      final form = fields.map<String, dynamic>(
        (k, v) => MapEntry(k, v is bool ? (v ? '1' : '0') : v.toString()),
      );
      body = FormData.fromMap({
        ...form,
        'selfie': MultipartFile.fromBytes(
          selfie,
          filename: 'selfie.jpg',
          contentType: DioMediaType('image', 'jpeg'),
        ),
      });
    } else {
      body = fields;
    }

    final res = await _client.post('/attendance/${item.type.path}', body: body);
    return CheckResult.fromJson(JsonReader.of(res.data));
  }

  @override
  Future<Uint8List> selfieBytes(String selfieUrl) {
    // Rebuild the path against the configured server so a different APP_URL
    // host never receives the bearer token.
    final i = selfieUrl.indexOf(AppConfig.apiPrefix);
    final path = i >= 0
        ? selfieUrl.substring(i + AppConfig.apiPrefix.length)
        : selfieUrl;
    return _client.getBytes(path);
  }
}
