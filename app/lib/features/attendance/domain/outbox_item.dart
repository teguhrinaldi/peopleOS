import 'package:flutter/foundation.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/l10n/generated/app_localizations.dart';

enum CheckType {
  checkIn('CHECK_IN', 'check-in'),
  checkOut('CHECK_OUT', 'check-out');

  const CheckType(this.wire, this.path);
  final String wire;

  /// Path segment under `/attendance/`.
  final String path;

  /// Localized "Check in" / "Check out".
  String label(AppLocalizations l10n) => this == checkIn
      ? l10n.attendanceCheckTypeIn
      : l10n.attendanceCheckTypeOut;

  static CheckType parse(String? raw) =>
      raw == 'CHECK_OUT' ? checkOut : checkIn;
}

/// Local delivery state of an attendance action:
/// LOCAL -> PENDING_SYNC -> SYNCED | SYNC_FAILED.
enum SyncState {
  local('LOCAL'),
  pendingSync('PENDING_SYNC'),
  synced('SYNCED'),
  syncFailed('SYNC_FAILED');

  const SyncState(this.wire);
  final String wire;

  static SyncState parse(String? raw) => SyncState.values.firstWhere(
    (s) => s.wire == raw,
    orElse: () => SyncState.pendingSync,
  );
}

/// One user check-in/out action persisted before any network call.
/// [idempotencyKey] is generated once and re-sent on every retry.
@immutable
class OutboxItem {
  const OutboxItem({
    required this.idempotencyKey,
    required this.employeeId,
    required this.type,
    required this.deviceCapturedAt,
    required this.createdAt,
    this.latitude,
    this.longitude,
    this.accuracyMeters,
    this.locationCapturedAt,
    this.isMockLocation = false,
    this.selfieRef,
    this.syncState = SyncState.local,
    this.attempts = 0,
    this.lastError,
    this.lastErrorCode,
    this.nextAttemptAt,
    this.utcOffsetMinutes = 0,
    this.siteName,
    this.clientDistanceMeters,
    this.isOfflineSync = false,
  });

  final String idempotencyKey;
  final int employeeId;
  final CheckType type;
  final double? latitude;
  final double? longitude;
  final double? accuracyMeters;
  final DateTime? locationCapturedAt;
  final DateTime deviceCapturedAt;
  final DateTime createdAt;
  final bool isMockLocation;
  final String? selfieRef;
  final SyncState syncState;
  final int attempts;
  final String? lastError;
  final String? lastErrorCode;
  final DateTime? nextAttemptAt;

  /// Company UTC offset at capture time, for rendering wall-clock time.
  final int utcOffsetMinutes;
  final String? siteName;

  /// Distance computed on the device for display only; the server decides.
  final double? clientDistanceMeters;

  /// True once a replay was attempted after the first send failed by
  /// connectivity; sent to the server as `is_offline_sync`.
  final bool isOfflineSync;

  /// Readable reason for the last failed delivery, localized at display time
  /// from the stored error code. Server free text (unknown codes) is shown
  /// as received.
  String? errorText(AppLocalizations l10n) {
    final code = lastErrorCode;
    switch (code) {
      case 'NO_CONNECTION':
        return l10n.attendanceErrNoConnection;
      case 'TIMEOUT':
        return l10n.attendanceErrTimeout;
      case 'SERVER_ERROR':
        return l10n.attendanceErrServer;
      case 'TOO_MANY_REQUESTS':
        return l10n.attendanceErrRateLimited;
      case 'UNAUTHENTICATED':
        return l10n.attendanceErrSession;
    }
    if (lastError == null && code == null) return null;
    final text = describeAttendanceError(
      UnknownException(lastError ?? '', code),
      l10n,
    );
    return text.isEmpty ? null : text;
  }

  bool get isActive =>
      syncState == SyncState.local || syncState == SyncState.pendingSync;

  OutboxItem copyWith({
    SyncState? syncState,
    int? attempts,
    String? lastError,
    String? lastErrorCode,
    DateTime? nextAttemptAt,
    bool clearError = false,
    bool clearNextAttempt = false,
    bool? isOfflineSync,
  }) => OutboxItem(
    idempotencyKey: idempotencyKey,
    employeeId: employeeId,
    type: type,
    latitude: latitude,
    longitude: longitude,
    accuracyMeters: accuracyMeters,
    locationCapturedAt: locationCapturedAt,
    deviceCapturedAt: deviceCapturedAt,
    createdAt: createdAt,
    isMockLocation: isMockLocation,
    selfieRef: selfieRef,
    syncState: syncState ?? this.syncState,
    attempts: attempts ?? this.attempts,
    lastError: clearError ? null : (lastError ?? this.lastError),
    lastErrorCode: clearError ? null : (lastErrorCode ?? this.lastErrorCode),
    nextAttemptAt: clearNextAttempt
        ? null
        : (nextAttemptAt ?? this.nextAttemptAt),
    utcOffsetMinutes: utcOffsetMinutes,
    siteName: siteName,
    clientDistanceMeters: clientDistanceMeters,
    isOfflineSync: isOfflineSync ?? this.isOfflineSync,
  );

  Map<String, dynamic> toJson() => {
    'idempotency_key': idempotencyKey,
    'employee_id': employeeId,
    'type': type.wire,
    'latitude': latitude,
    'longitude': longitude,
    'accuracy_meters': accuracyMeters,
    'location_captured_at': locationCapturedAt?.toUtc().toIso8601String(),
    'device_captured_at': deviceCapturedAt.toUtc().toIso8601String(),
    'created_at': createdAt.toUtc().toIso8601String(),
    'is_mock_location': isMockLocation,
    'selfie_ref': selfieRef,
    'sync_state': syncState.wire,
    'attempts': attempts,
    'last_error': lastError,
    'last_error_code': lastErrorCode,
    'next_attempt_at': nextAttemptAt?.toUtc().toIso8601String(),
    'utc_offset_minutes': utcOffsetMinutes,
    'site_name': siteName,
    'client_distance_meters': clientDistanceMeters,
    'is_offline_sync': isOfflineSync,
  };

  factory OutboxItem.fromJson(Map<String, dynamic> j) {
    DateTime? dt(String k) =>
        j[k] == null ? null : DateTime.tryParse(j[k] as String);
    return OutboxItem(
      idempotencyKey: j['idempotency_key'] as String,
      employeeId: (j['employee_id'] as num).toInt(),
      type: CheckType.parse(j['type'] as String?),
      latitude: (j['latitude'] as num?)?.toDouble(),
      longitude: (j['longitude'] as num?)?.toDouble(),
      accuracyMeters: (j['accuracy_meters'] as num?)?.toDouble(),
      locationCapturedAt: dt('location_captured_at'),
      deviceCapturedAt: dt('device_captured_at') ?? DateTime.now().toUtc(),
      createdAt: dt('created_at') ?? DateTime.now().toUtc(),
      isMockLocation: j['is_mock_location'] as bool? ?? false,
      selfieRef: j['selfie_ref'] as String?,
      syncState: SyncState.parse(j['sync_state'] as String?),
      attempts: (j['attempts'] as num?)?.toInt() ?? 0,
      lastError: j['last_error'] as String?,
      lastErrorCode: j['last_error_code'] as String?,
      nextAttemptAt: dt('next_attempt_at'),
      utcOffsetMinutes: (j['utc_offset_minutes'] as num?)?.toInt() ?? 0,
      siteName: j['site_name'] as String?,
      clientDistanceMeters: (j['client_distance_meters'] as num?)?.toDouble(),
      isOfflineSync: j['is_offline_sync'] as bool? ?? false,
    );
  }
}
