import 'dart:async';
import 'dart:typed_data';

import 'package:peopleos/core/network/connectivity_service.dart';
import 'package:peopleos/core/platform/location_service.dart';
import 'package:peopleos/core/platform/selfie_service.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/core/utils/zoned_time.dart';
import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/features/attendance/domain/attendance_repository.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';
import 'package:peopleos/features/auth/domain/auth_repository.dart';
import 'package:peopleos/features/auth/domain/session.dart';
import 'package:peopleos/features/leave/domain/leave_models.dart';
import 'package:peopleos/features/leave/domain/leave_repository.dart';
import 'package:peopleos/features/notifications/domain/app_notification.dart';
import 'package:peopleos/features/requests/domain/request_models.dart';
import 'package:peopleos/features/requests/domain/requests_repository.dart';

import 'sample_json.dart';

class FakeLocationService implements LocationService {
  FakeLocationService({this.fix, this.problem});

  LocationFix? fix;
  LocationProblem? problem;
  int calls = 0;
  int openedAppSettings = 0;
  int openedLocationSettings = 0;

  static LocationFix atOffice({double dLat = 0.0003, bool mocked = false}) =>
      LocationFix(
        latitude: -6.9218 + dLat,
        longitude: 107.6071,
        accuracyMeters: 10,
        capturedAt: DateTime.now(),
        isMocked: mocked,
      );

  @override
  Future<LocationFix> currentFix() async {
    calls++;
    final p = problem;
    if (p != null) throw LocationException(p);
    return fix ?? atOffice();
  }

  @override
  Future<void> openAppSettings() async => openedAppSettings++;

  @override
  Future<void> openLocationSettings() async => openedLocationSettings++;
}

class FakeSelfieService implements SelfieService {
  FakeSelfieService({this.photo});
  SelfiePhoto? photo;
  int calls = 0;

  @override
  Future<SelfiePhoto?> capture() async {
    calls++;
    return photo;
  }
}

/// 1x1 transparent PNG, valid for Image.memory.
final Uint8List tinyPng = Uint8List.fromList(const [
  0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A, 0x00, 0x00, 0x00, 0x0D, //
  0x49, 0x48, 0x44, 0x52, 0x00, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00, 0x01,
  0x08, 0x06, 0x00, 0x00, 0x00, 0x1F, 0x15, 0xC4, 0x89, 0x00, 0x00, 0x00,
  0x0D, 0x49, 0x44, 0x41, 0x54, 0x78, 0x9C, 0x63, 0xF8, 0xFF, 0xFF, 0x3F,
  0x00, 0x05, 0xFE, 0x02, 0xFE, 0xA7, 0x35, 0x81, 0x84, 0x00, 0x00, 0x00,
  0x00, 0x49, 0x45, 0x4E, 0x44, 0xAE, 0x42, 0x60, 0x82,
]);

class FakeConnectivity implements ConnectivityService {
  FakeConnectivity({this._online = true});

  bool _online;
  final _controller = StreamController<bool>.broadcast();

  void set(bool online) {
    _online = online;
    _controller.add(online);
  }

  @override
  Future<bool> get isOnline async => _online;

  @override
  Stream<bool> get onlineChanges => _controller.stream;
}

/// Attendance repository scripted per test. [submitHandler] decides every
/// submit outcome; all submitted items are recorded.
class FakeAttendanceRepository implements AttendanceRepository {
  FakeAttendanceRepository({Map<String, dynamic>? today})
    : todayData = today ?? todayJson();

  Map<String, dynamic> todayData;
  Object? todayError;
  int todayCalls = 0;
  List<AttendanceRecord> records = [];
  Object? historyError;

  final List<OutboxItem> submitted = [];
  final List<Uint8List?> selfies = [];

  FutureOr<CheckResult> Function(OutboxItem item)? submitHandler;

  @override
  Future<TodayAttendance> today() async {
    todayCalls++;
    if (todayError != null) throw todayError!;
    return TodayAttendance.fromJson(JsonReader(todayData));
  }

  @override
  Future<List<AttendanceRecord>> history({
    required DateTime from,
    required DateTime to,
  }) async {
    if (historyError != null) throw historyError!;
    return records;
  }

  @override
  Future<AttendanceRecord> detail(int id) async => records.first;

  @override
  Future<CheckResult> submit(OutboxItem item, {Uint8List? selfie}) async {
    submitted.add(item);
    selfies.add(selfie);
    final handler = submitHandler;
    if (handler == null) return okResult(item.type);
    return handler(item);
  }

  @override
  Future<Uint8List> selfieBytes(String selfieUrl) async => tinyPng;

  static CheckResult okResult(CheckType type, {bool replayed = false}) =>
      CheckResult.fromJson(
        JsonReader(
          checkResultJson(replayed: replayed, out: type == CheckType.checkOut),
        ),
      );
}

class FakeAuthRepository implements AuthRepository {
  FakeAuthRepository({this.signedIn = false, this.status = 'ACTIVE'});

  bool signedIn;
  String status;
  Object? loginError;
  int loginCalls = 0;
  String? lastEmail;
  int logoutCalls = 0;

  String? serverLocale;

  Session get _session =>
      Session.fromJson(loginJson(status: status, serverLocale: serverLocale));

  @override
  Future<Session> login({
    required String email,
    required String password,
  }) async {
    loginCalls++;
    lastEmail = email;
    if (loginError != null) throw loginError!;
    signedIn = true;
    return _session;
  }

  @override
  Future<Session?> restore() async => signedIn ? _session : null;

  @override
  Future<Session> refresh() async => _session;

  final List<String?> savedLocales = [];
  bool savePreferenceResult = true;

  @override
  Future<bool> savePreferredLocale(String? locale) async {
    savedLocales.add(locale);
    return savePreferenceResult;
  }

  @override
  Future<void> logout() async {
    logoutCalls++;
    signedIn = false;
  }
}

class FakeRequestsRepository implements RequestsRepository {
  List<CorrectionRequest> correctionList = [];
  List<OvertimeRequest> overtimeList = [];
  Object? createError;
  int createCalls = 0;
  ZonedTime? lastCheckIn;
  ZonedTime? lastCheckOut;

  @override
  Future<List<CorrectionRequest>> corrections() async => correctionList;

  @override
  Future<CorrectionRequest> createCorrection({
    required DateTime workDate,
    ZonedTime? checkIn,
    ZonedTime? checkOut,
    required String reason,
  }) async {
    createCalls++;
    lastCheckIn = checkIn;
    lastCheckOut = checkOut;
    if (createError != null) throw createError!;
    return CorrectionRequest.fromJson(JsonReader(correctionJson()));
  }

  @override
  Future<CorrectionRequest> cancelCorrection(int id) async =>
      CorrectionRequest.fromJson(
        JsonReader(correctionJson(status: 'CANCELLED')),
      );

  @override
  Future<List<OvertimeRequest>> overtime() async => overtimeList;

  @override
  Future<OvertimeRequest> createOvertime({
    required DateTime workDate,
    required ZonedTime start,
    required ZonedTime end,
    required String reason,
  }) async {
    createCalls++;
    if (createError != null) throw createError!;
    return OvertimeRequest.fromJson(JsonReader(overtimeJson()));
  }

  @override
  Future<OvertimeRequest> cancelOvertime(int id) async =>
      OvertimeRequest.fromJson(JsonReader(overtimeJson(status: 'CANCELLED')));
}

/// Leave repository with scripted data and failures.
class FakeLeaveRepository implements LeaveRepository {
  List<LeaveType> typeList = [
    LeaveType.fromJson(JsonReader(leaveTypeJson())),
    LeaveType.fromJson(
      JsonReader(
        leaveTypeJson(
          id: 2,
          code: 'SICK',
          name: 'Sick Leave',
          unlimited: true,
          requiresReason: true,
          notice: 0,
        ),
      ),
    ),
  ];
  List<LeaveBalance> balanceList = [
    LeaveBalance.fromJson(JsonReader(leaveBalanceJson())),
    LeaveBalance.fromJson(JsonReader(leaveBalanceJson(id: 2, unlimited: true))),
  ];
  List<LeaveRequest> requestList = [];
  Object? previewError;
  Object? createError;
  LeavePreview previewResult = LeavePreview.fromJson(
    JsonReader(leavePreviewJson()),
  );
  final List<(LeaveDraft, bool)> created = [];
  final List<int> cancelled = [];
  final List<int> submitted = [];
  int previewCalls = 0;

  @override
  Future<List<LeaveType>> types() async => typeList;

  @override
  Future<List<LeaveBalance>> balances({int? year}) async => balanceList;

  @override
  Future<List<LeaveRequest>> requests({LeaveStatus? status}) async =>
      requestList;

  @override
  Future<LeaveRequest> detail(int id) async =>
      requestList.firstWhere((r) => r.id == id);

  @override
  Future<LeavePreview> preview(LeaveDraft draft) async {
    previewCalls++;
    if (previewError != null) throw previewError!;
    return previewResult;
  }

  @override
  Future<LeaveRequest> create(LeaveDraft draft, {required bool submit}) async {
    created.add((draft, submit));
    if (createError != null) throw createError!;
    return LeaveRequest.fromJson(
      JsonReader(leaveRequestJson(status: submit ? 'PENDING' : 'DRAFT')),
    );
  }

  @override
  Future<LeaveRequest> update(int id, LeaveDraft draft) async =>
      requestList.firstWhere((r) => r.id == id);

  @override
  Future<LeaveRequest> submit(int id) async {
    submitted.add(id);
    return LeaveRequest.fromJson(JsonReader(leaveRequestJson()));
  }

  @override
  Future<LeaveRequest> cancel(int id, {String? reason}) async {
    cancelled.add(id);
    if (createError != null) throw createError!;
    return LeaveRequest.fromJson(
      JsonReader(leaveRequestJson(status: 'CANCELLED')),
    );
  }
}

class FakeNotificationsRepository implements NotificationsRepository {
  List<AppNotification> items = [
    AppNotification.fromJson(JsonReader(notificationJson(id: 2, read: false))),
    AppNotification.fromJson(
      JsonReader(
        notificationJson(
          id: 1,
          type: 'attendance_correction.approved',
          title: 'Correction approved',
          entityType: r'App\Models\AttendanceCorrection',
          entityId: 91,
          read: true,
        ),
      ),
    ),
  ];
  final List<int> markedRead = [];
  int markAllCalls = 0;

  int get _unread => items.where((n) => !n.isRead).length;

  @override
  Future<NotificationPage> list({int page = 1}) async => NotificationPage(
    items: List.of(items),
    unreadCount: _unread,
    hasMore: false,
  );

  @override
  Future<int> unreadCount() async => _unread;

  @override
  Future<void> markRead(int id) async {
    markedRead.add(id);
    items = [for (final n in items) n.id == id ? n.markedRead() : n];
  }

  @override
  Future<int> markAllRead() async {
    markAllCalls++;
    final count = _unread;
    items = [for (final n in items) n.markedRead()];
    return count;
  }
}
