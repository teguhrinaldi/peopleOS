import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/platform/location_service.dart';
import 'package:peopleos/core/platform/selfie_service.dart';
import 'package:peopleos/core/utils/geo.dart';
import 'package:peopleos/features/attendance/application/attendance_sync_service.dart';
import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';
import 'package:peopleos/features/attendance/presentation/attendance_providers.dart';
import 'package:peopleos/features/attendance/presentation/outbox_controller.dart';
import 'package:peopleos/features/auth/presentation/auth_controller.dart';

enum CheckPhase { locating, locationProblem, ready, submitting, done, rejected }

@immutable
class CheckFlowState {
  const CheckFlowState({
    this.phase = CheckPhase.locating,
    this.fix,
    this.problem,
    this.distanceMeters,
    this.insideArea,
    this.selfie,
    this.previewSelfie,
    this.outcome,
    this.error,
  });

  final CheckPhase phase;
  final LocationFix? fix;
  final LocationProblem? problem;

  /// Device-side estimate for UX only. The server decides.
  final double? distanceMeters;
  final bool? insideArea;
  final SelfiePhoto? selfie;

  /// Photo just taken, awaiting "Use photo" / "Retake".
  final SelfiePhoto? previewSelfie;
  final SubmitOutcome? outcome;
  final AppException? error;

  CheckFlowState copyWith({
    CheckPhase? phase,
    LocationFix? fix,
    LocationProblem? problem,
    double? distanceMeters,
    bool? insideArea,
    SelfiePhoto? selfie,
    SelfiePhoto? previewSelfie,
    SubmitOutcome? outcome,
    AppException? error,
    bool clearSelfie = false,
    bool clearPreview = false,
    bool clearError = false,
    bool clearProblem = false,
  }) => CheckFlowState(
    phase: phase ?? this.phase,
    fix: fix ?? this.fix,
    problem: clearProblem ? null : (problem ?? this.problem),
    distanceMeters: distanceMeters ?? this.distanceMeters,
    insideArea: insideArea ?? this.insideArea,
    selfie: clearSelfie ? null : (selfie ?? this.selfie),
    previewSelfie: clearPreview ? null : (previewSelfie ?? this.previewSelfie),
    outcome: outcome ?? this.outcome,
    error: clearError ? null : (error ?? this.error),
  );
}

class CheckFlowController extends Notifier<CheckFlowState> {
  CheckFlowController(this.type);
  final CheckType type;

  @override
  CheckFlowState build() => const CheckFlowState();

  Schedule? get _schedule => ref.read(effectiveTodayProvider).value?.schedule;

  bool get requiresSelfie => _schedule?.policy.requireSelfie ?? false;

  bool get canSubmit =>
      state.phase == CheckPhase.ready &&
      state.fix != null &&
      (!requiresSelfie || state.selfie != null);

  /// One high-accuracy foreground fix, requested only when the screen opens.
  Future<void> locate() async {
    state = state.copyWith(
      phase: CheckPhase.locating,
      clearProblem: true,
      clearError: true,
    );
    try {
      final fix = await ref.read(locationServiceProvider).currentFix();
      final site = _schedule?.location;
      final distance = site == null
          ? null
          : haversineMeters(
              fix.latitude,
              fix.longitude,
              site.latitude,
              site.longitude,
            );
      state = state.copyWith(
        phase: CheckPhase.ready,
        fix: fix,
        distanceMeters: distance,
        insideArea: distance == null || site == null
            ? null
            : distance <= site.radiusMeters,
      );
    } on LocationException catch (e) {
      state = state.copyWith(
        phase: CheckPhase.locationProblem,
        problem: e.problem,
      );
    }
  }

  Future<void> openSettings() async {
    final location = ref.read(locationServiceProvider);
    if (state.problem == LocationProblem.serviceDisabled) {
      await location.openLocationSettings();
    } else {
      await location.openAppSettings();
    }
  }

  Future<void> takeSelfie() async {
    final photo = await ref.read(selfieServiceProvider).capture();
    if (photo != null) state = state.copyWith(previewSelfie: photo);
  }

  void useSelfie() {
    final preview = state.previewSelfie;
    if (preview == null) return;
    state = state.copyWith(selfie: preview, clearPreview: true);
  }

  void discardPreview() => state = state.copyWith(clearPreview: true);

  void removeSelfie() => state = state.copyWith(clearSelfie: true);

  Future<void> submit() async {
    if (!canSubmit) return;
    final employee = ref.read(authControllerProvider).employee;
    if (employee == null) return;
    final today = ref.read(effectiveTodayProvider).value;
    state = state.copyWith(phase: CheckPhase.submitting, clearError: true);
    try {
      final outcome = await ref
          .read(outboxControllerProvider.notifier)
          .perform(
            CheckCapture(
              employeeId: employee.id,
              type: type,
              fix: state.fix,
              selfie: state.selfie?.bytes,
              utcOffsetMinutes:
                  today?.server.serverTime.offset.inMinutes ??
                  DateTime.now().timeZoneOffset.inMinutes,
              siteName: _schedule?.location?.name,
              clientDistanceMeters: state.distanceMeters,
            ),
          );
      state = state.copyWith(phase: CheckPhase.done, outcome: outcome);
    } on AppException catch (e) {
      state = state.copyWith(phase: CheckPhase.rejected, error: e);
    }
  }

  /// Back from a rejection to the review state (e.g. after moving closer).
  void backToReview() =>
      state = state.copyWith(phase: CheckPhase.ready, clearError: true);
}

final checkFlowControllerProvider = NotifierProvider.autoDispose
    .family<CheckFlowController, CheckFlowState, CheckType>(
      CheckFlowController.new,
    );
