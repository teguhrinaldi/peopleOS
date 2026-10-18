import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/widgets/app_top_bar.dart';
import 'package:peopleos/core/widgets/buttons.dart';
import 'package:peopleos/core/widgets/skeleton.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';
import 'package:peopleos/features/attendance/presentation/attendance_providers.dart';
import 'package:peopleos/features/attendance/presentation/check_flow_controller.dart';
import 'package:peopleos/features/attendance/presentation/widgets/check_result_view.dart';
import 'package:peopleos/features/attendance/presentation/widgets/check_widgets.dart';
import 'package:peopleos/features/attendance/presentation/widgets/location_map_card.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Check-in / check-out flow:
/// location permission -> one GPS fix -> (selfie if policy) -> confirm -> result.
class CheckFlowScreen extends ConsumerStatefulWidget {
  const CheckFlowScreen({super.key, required this.type});

  final CheckType type;

  @override
  ConsumerState<CheckFlowScreen> createState() => _CheckFlowScreenState();
}

class _CheckFlowScreenState extends ConsumerState<CheckFlowScreen> {
  @override
  void initState() {
    super.initState();
    // Location is requested only now, when the user starts a check.
    Future.microtask(
      () =>
          ref.read(checkFlowControllerProvider(widget.type).notifier).locate(),
    );
  }

  @override
  Widget build(BuildContext context) {
    final type = widget.type;
    final state = ref.watch(checkFlowControllerProvider(type));
    final controller = ref.read(checkFlowControllerProvider(type).notifier);
    final today = ref.watch(effectiveTodayProvider).value;
    final schedule = today?.schedule;
    final requireSelfie = schedule?.policy.requireSelfie ?? false;
    final l10n = context.l10n;
    final title = state.phase == CheckPhase.done
        ? (type == CheckType.checkIn
              ? l10n.checkTitleCheckIn
              : l10n.checkTitleCheckOut)
        : type == CheckType.checkIn
        ? l10n.checkConfirmLocation
        : l10n.checkConfirmCheckOutTitle;

    if (state.previewSelfie != null) {
      return Scaffold(
        appBar: AppTopBar(
          title: l10n.checkSelfiePreviewTitle,
          onBack: controller.discardPreview,
        ),
        body: SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(PeopleSpacing.page),
            child: SelfiePreview(
              photo: state.previewSelfie!,
              onRetake: () => unawaited(controller.takeSelfie()),
              onUse: controller.useSelfie,
            ),
          ),
        ),
      );
    }

    return Scaffold(
      appBar: AppTopBar(title: title),
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(
              maxWidth: Breakpoints.formMax + 80,
            ),
            child: switch (state.phase) {
              CheckPhase.locating => const _Locating(),
              CheckPhase.locationProblem => LocationProblemView(
                problem: state.problem!,
                onRetry: () => unawaited(controller.locate()),
                onOpenSettings: () => unawaited(controller.openSettings()),
              ),
              CheckPhase.done => CheckResultView(
                type: type,
                outcome: state.outcome!,
                onDone: () => context.pop(),
              ),
              _ => _Review(
                type: type,
                state: state,
                requireSelfie: requireSelfie,
                onTakeSelfie: () => unawaited(controller.takeSelfie()),
                onRemoveSelfie: controller.removeSelfie,
                onConfirm: () => unawaited(controller.submit()),
                onBackToReview: controller.backToReview,
                onRefreshLocation: () => unawaited(controller.locate()),
                canSubmit: controller.canSubmit,
              ),
            },
          ),
        ),
      ),
    );
  }
}

class _Locating extends ConsumerWidget {
  const _Locating();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final site = ref.watch(effectiveTodayProvider).value?.schedule?.location;
    return Padding(
      padding: const EdgeInsets.all(PeopleSpacing.page),
      child: Column(
        children: [
          LocationMapCard(site: site, loading: true),
          const SizedBox(height: PeopleSpacing.md),
          const Skeleton(height: 76, radius: PeopleRadii.card),
          const SizedBox(height: PeopleSpacing.lg),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const SizedBox(
                width: 18,
                height: 18,
                child: CircularProgressIndicator(strokeWidth: 2.2),
              ),
              const SizedBox(width: PeopleSpacing.sm),
              Text(
                context.l10n.checkGettingLocation,
                style: Theme.of(context).textTheme.bodyMedium!
                    .copyWith(color: context.colors.textSecondary),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _Review extends ConsumerWidget {
  const _Review({
    required this.type,
    required this.state,
    required this.requireSelfie,
    required this.onTakeSelfie,
    required this.onRemoveSelfie,
    required this.onConfirm,
    required this.onBackToReview,
    required this.onRefreshLocation,
    required this.canSubmit,
  });

  final CheckType type;
  final CheckFlowState state;
  final bool requireSelfie;
  final VoidCallback onTakeSelfie;
  final VoidCallback onRemoveSelfie;
  final VoidCallback onConfirm;
  final VoidCallback onBackToReview;
  final VoidCallback onRefreshLocation;
  final bool canSubmit;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final site = ref.watch(effectiveTodayProvider).value?.schedule?.location;
    final online = ref.watch(isOnlineProvider);
    final rejected = state.phase == CheckPhase.rejected;
    final submitting = state.phase == CheckPhase.submitting;
    final fix = state.fix;
    final l10n = context.l10n;

    return ListView(
      padding: const EdgeInsets.all(PeopleSpacing.page),
      children: [
        LocationMapCard(
          site: site,
          userLat: fix?.latitude,
          userLng: fix?.longitude,
        ),
        const SizedBox(height: PeopleSpacing.md),
        if (rejected)
          _RejectionCard(error: state.error!)
        else
          AreaStatusCard(
            site: site,
            distanceMeters: state.distanceMeters,
            inside: state.insideArea,
            accuracyMeters: fix?.accuracyMeters,
          ),
        if (requireSelfie) ...[
          const SizedBox(height: PeopleSpacing.md),
          SelfieTile(
            photo: state.selfie,
            onTake: onTakeSelfie,
            onRemove: onRemoveSelfie,
          ),
        ],
        const SizedBox(height: PeopleSpacing.md),
        ChecklistCard(
          rows: [
            CheckRow(
              l10n.checkGps,
              fix != null,
              note: fix == null
                  ? l10n.checkWaiting
                  : l10n.checkLocationCaptured,
            ),
            CheckRow(
              l10n.checkGeofence,
              state.insideArea ?? false,
              warn: state.insideArea == false,
              note: switch (state.insideArea) {
                true => l10n.checkWithinArea,
                false => l10n.checkOutsideArea,
                null => l10n.checkCheckedByServer,
              },
            ),
            if (requireSelfie) CheckRow(l10n.checkSelfie, state.selfie != null),
          ],
        ),
        if (!online) ...[
          const SizedBox(height: PeopleSpacing.md),
          Text(
            l10n.checkOfflineNote,
            style: Theme.of(context).textTheme.bodySmall!
                .copyWith(color: context.colors.warningText),
          ),
        ],
        const SizedBox(height: PeopleSpacing.lg),
        if (rejected) ...[
          PrimaryButton(
            label: l10n.commonTryAgain,
            icon: Icons.refresh_rounded,
            onPressed: () {
              onBackToReview();
              onRefreshLocation();
            },
          ),
          const SizedBox(height: PeopleSpacing.xs),
          OutlineButton(
            label: l10n.checkBackToHome,
            onPressed: () => context.pop(),
          ),
        ] else ...[
          PrimaryButton(
            label: type == CheckType.checkIn
                ? l10n.checkConfirmCheckIn
                : l10n.checkConfirmCheckOut,
            loading: submitting,
            onPressed: canSubmit && !submitting ? onConfirm : null,
          ),
          if (requireSelfie && state.selfie == null && fix != null)
            Padding(
              padding: const EdgeInsets.only(top: PeopleSpacing.xs),
              child: Text(
                l10n.checkTakeSelfieToContinue,
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.bodySmall!
                    .copyWith(color: context.colors.textMuted),
              ),
            ),
        ],
      ],
    );
  }
}

class _RejectionCard extends StatelessWidget {
  const _RejectionCard({required this.error});
  final AppException error;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    return Semantics(
      liveRegion: true,
      container: true,
      child: Container(
        padding: const EdgeInsets.all(PeopleSpacing.md),
        decoration: BoxDecoration(
          color: c.errorSoft,
          borderRadius: PeopleRadii.cardRadius,
          border: Border.all(color: c.error.withValues(alpha: 0.35)),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(Icons.error_outline_rounded, color: c.errorText, size: 24),
            const SizedBox(width: PeopleSpacing.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    error.code == 'OUTSIDE_GEOFENCE'
                        ? context.l10n.checkRejectedLocation
                        : context.l10n.checkRejectedAttendance,
                    style: t.titleSmall!.copyWith(color: c.errorText),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    describeAttendanceError(error, context.l10n),
                    style: t.bodyMedium!.copyWith(color: c.textPrimary),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
