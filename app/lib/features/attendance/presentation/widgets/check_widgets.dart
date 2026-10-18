import 'package:flutter/material.dart';
import 'package:peopleos/core/platform/location_service.dart';
import 'package:peopleos/core/platform/selfie_service.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/utils/formatters.dart';
import 'package:peopleos/core/widgets/app_card.dart';
import 'package:peopleos/core/widgets/buttons.dart';
import 'package:peopleos/core/widgets/state_views.dart';
import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/l10n/l10n.dart';

/// "You are within work area / outside the work area" card.
/// The distance is computed on the device for guidance only.
class AreaStatusCard extends StatelessWidget {
  const AreaStatusCard({
    super.key,
    required this.site,
    required this.distanceMeters,
    required this.inside,
    required this.accuracyMeters,
  });

  final WorkSite? site;
  final double? distanceMeters;
  final bool? inside;
  final double? accuracyMeters;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final l10n = context.l10n;
    final ok = inside ?? true;
    final tint = ok ? c.success : c.warning;
    final fg = ok ? c.successText : c.warningText;
    final title = inside == null
        ? l10n.checkLocationCaptured
        : ok
        ? l10n.checkAreaInside
        : l10n.checkAreaOutside;
    final detail = site == null
        ? l10n.checkNoSite
        : distanceMeters == null
        ? site!.name
        : ok
        ? l10n.checkDistanceFromSite(
            formatDistance(distanceMeters!, locale: context.fmt.tag),
            site!.name,
          )
        : l10n.checkDistanceFromSiteLimit(
            formatDistance(distanceMeters!, locale: context.fmt.tag),
            site!.name,
            site!.radiusMeters.round(),
          );
    return Semantics(
      container: true,
      liveRegion: true,
      child: Container(
        padding: const EdgeInsets.all(PeopleSpacing.md),
        decoration: BoxDecoration(
          color: (ok ? c.successSoft : c.warningSoft),
          borderRadius: PeopleRadii.cardRadius,
          border: Border.all(color: tint.withValues(alpha: 0.35)),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(
              ok ? Icons.location_on_rounded : Icons.wrong_location_outlined,
              color: fg,
              size: 24,
            ),
            const SizedBox(width: PeopleSpacing.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: t.titleSmall!.copyWith(color: fg)),
                  const SizedBox(height: 2),
                  Text(
                    detail,
                    style: t.bodyMedium!.copyWith(color: c.textPrimary),
                  ),
                  if (site != null && distanceMeters != null)
                    Text(
                      l10n.checkEstimatedOnDevice,
                      style: t.bodySmall!.copyWith(color: c.textSecondary),
                    ),
                  if (accuracyMeters != null)
                    Text(
                      l10n.checkGpsAccuracy(accuracyMeters!.round()),
                      style: t.bodySmall!.copyWith(color: c.textSecondary),
                    ),
                  if (!ok)
                    Padding(
                      padding: const EdgeInsets.only(top: PeopleSpacing.xxs),
                      child: Text(
                        l10n.checkServerDecides,
                        style: t.bodySmall!.copyWith(color: c.textSecondary),
                      ),
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

class CheckRow {
  const CheckRow(this.label, this.done, {this.warn = false, this.note});
  final String label;
  final bool done;
  final bool warn;
  final String? note;
}

/// GPS / Geofence / Selfie checklist. State is conveyed by icon AND text.
class ChecklistCard extends StatelessWidget {
  const ChecklistCard({super.key, required this.rows});

  final List<CheckRow> rows;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    return AppCard(
      shadow: false,
      padding: const EdgeInsets.symmetric(
        horizontal: PeopleSpacing.md,
        vertical: PeopleSpacing.xs,
      ),
      child: Column(
        children: [
          for (final r in rows)
            ConstrainedBox(
              constraints: const BoxConstraints(minHeight: 44),
              child: Row(
                children: [
                  Expanded(child: Text(r.label, style: t.bodyMedium)),
                  Text(
                    r.note ??
                        (r.done
                            ? context.l10n.checkDone
                            : context.l10n.checkWaiting),
                    style: t.bodySmall!.copyWith(
                      color: r.warn
                          ? c.warningText
                          : r.done
                          ? c.successText
                          : c.textMuted,
                    ),
                  ),
                  const SizedBox(width: PeopleSpacing.xs),
                  Icon(
                    r.warn
                        ? Icons.warning_amber_rounded
                        : r.done
                        ? Icons.check_circle_rounded
                        : Icons.radio_button_unchecked,
                    size: 20,
                    color: r.warn
                        ? c.warningText
                        : r.done
                        ? c.success
                        : c.textMuted,
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

/// Selfie tile: shows the accepted photo or a prompt to take one.
class SelfieTile extends StatelessWidget {
  const SelfieTile({
    super.key,
    required this.photo,
    required this.onTake,
    required this.onRemove,
  });

  final SelfiePhoto? photo;
  final VoidCallback onTake;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final l10n = context.l10n;
    if (photo == null) {
      return AppCard(
        shadow: false,
        padding: const EdgeInsets.all(PeopleSpacing.md),
        child: Row(
          children: [
            const IconTile(icon: Icons.photo_camera_outlined, size: 48),
            const SizedBox(width: PeopleSpacing.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(l10n.checkSelfieRequired, style: t.titleSmall),
                  Text(
                    l10n.checkSelfieRequiredMsg,
                    style: t.bodySmall!.copyWith(color: c.textSecondary),
                  ),
                ],
              ),
            ),
            const SizedBox(width: PeopleSpacing.xs),
            OutlineButton(
              label: l10n.checkTake,
              onPressed: onTake,
              expand: false,
            ),
          ],
        ),
      );
    }
    return AppCard(
      shadow: false,
      padding: const EdgeInsets.all(PeopleSpacing.sm),
      child: Row(
        children: [
          ClipRRect(
            borderRadius: PeopleRadii.tileRadius,
            child: Image.memory(
              photo!.bytes,
              width: 84,
              height: 84,
              fit: BoxFit.cover,
              semanticLabel: l10n.checkYourSelfie,
            ),
          ),
          const SizedBox(width: PeopleSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(l10n.checkSelfieReady, style: t.titleSmall),
                Text(
                  l10n.checkSelfieReadyMsg,
                  style: t.bodySmall!.copyWith(color: c.textSecondary),
                ),
              ],
            ),
          ),
          Column(
            children: [
              AppTextButton(label: l10n.checkRetake, onPressed: onTake),
              AppTextButton(label: l10n.checkRemove, onPressed: onRemove),
            ],
          ),
        ],
      ),
    );
  }
}

/// Full-width preview after the camera returns: Retake / Use photo.
class SelfiePreview extends StatelessWidget {
  const SelfiePreview({
    super.key,
    required this.photo,
    required this.onRetake,
    required this.onUse,
  });

  final SelfiePhoto photo;
  final VoidCallback onRetake;
  final VoidCallback onUse;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Expanded(
          child: ClipRRect(
            borderRadius: PeopleRadii.cardRadius,
            child: Image.memory(
              photo.bytes,
              fit: BoxFit.cover,
              semanticLabel: l10n.checkSelfiePreviewSemantic,
            ),
          ),
        ),
        const SizedBox(height: PeopleSpacing.md),
        Row(
          children: [
            Expanded(
              child: OutlineButton(
                label: l10n.checkRetake,
                icon: Icons.replay_rounded,
                onPressed: onRetake,
              ),
            ),
            const SizedBox(width: PeopleSpacing.sm),
            Expanded(
              child: PrimaryButton(
                label: l10n.checkUsePhoto,
                icon: Icons.check_rounded,
                onPressed: onUse,
              ),
            ),
          ],
        ),
      ],
    );
  }
}

/// Guidance for denied / off / permanently denied location.
class LocationProblemView extends StatelessWidget {
  const LocationProblemView({
    super.key,
    required this.problem,
    required this.onRetry,
    required this.onOpenSettings,
  });

  final LocationProblem problem;
  final VoidCallback onRetry;
  final VoidCallback onOpenSettings;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final (
      String title,
      String message,
      IconData icon,
      String? settings,
    ) = switch (problem) {
      LocationProblem.denied => (
        l10n.checkPermDeniedTitle,
        l10n.checkPermDeniedMsg,
        Icons.location_off_outlined,
        null,
      ),
      LocationProblem.deniedForever => (
        l10n.checkPermBlockedTitle,
        l10n.checkPermBlockedMsg,
        Icons.location_disabled_outlined,
        l10n.checkOpenAppSettings,
      ),
      LocationProblem.serviceDisabled => (
        l10n.checkServiceOffTitle,
        l10n.checkServiceOffMsg,
        Icons.gps_off_outlined,
        l10n.checkOpenLocationSettings,
      ),
      LocationProblem.unavailable => (
        l10n.checkUnavailableTitle,
        l10n.checkUnavailableMsg,
        Icons.gps_not_fixed_rounded,
        null,
      ),
    };
    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        EmptyState(title: title, message: message, icon: icon),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: PeopleSpacing.xl),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              PrimaryButton(
                label: l10n.commonTryAgain,
                icon: Icons.refresh_rounded,
                onPressed: onRetry,
              ),
              if (settings != null) ...[
                const SizedBox(height: PeopleSpacing.xs),
                OutlineButton(
                  label: settings,
                  icon: Icons.settings_outlined,
                  onPressed: onOpenSettings,
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}
