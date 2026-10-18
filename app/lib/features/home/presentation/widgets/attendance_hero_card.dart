import 'dart:async';

import 'package:flutter/material.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/utils/formatters.dart';
import 'package:peopleos/core/widgets/app_card.dart';
import 'package:peopleos/core/widgets/buttons.dart';
import 'package:peopleos/core/widgets/status_badge.dart';
import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/features/attendance/domain/effective_today.dart';
import 'package:peopleos/l10n/l10n.dart';

/// The signature card: everything comes from `GET /attendance/today`
/// (plus unsynced local actions), never hardcoded.
class AttendanceHeroCard extends StatelessWidget {
  const AttendanceHeroCard({
    super.key,
    required this.today,
    required this.onCheckIn,
    required this.onCheckOut,
    required this.onOpenRequests,
    required this.onOpenSync,
    this.now,
  });

  final EffectiveToday today;
  final VoidCallback onCheckIn;
  final VoidCallback onCheckOut;
  final VoidCallback onOpenRequests;
  final VoidCallback onOpenSync;

  /// Injected clock for tests.
  final DateTime Function()? now;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final l10n = context.l10n;
    final view = _HeroView.of(context, today);

    return AppCard(
      padding: const EdgeInsets.all(PeopleSpacing.lg),
      color: c.surface,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Semantics(
                  header: true,
                  child: Text(l10n.homeAttendance, style: t.titleMedium),
                ),
              ),
              if (view.badge != null) view.badge!,
            ],
          ),
          const SizedBox(height: PeopleSpacing.md),
          if (today.state == TodayState.checkedIn || today.isOpenForReview)
            _WorkingBody(today: today, now: now)
          else
            _StatusBody(view: view),
          if (today.hasPendingSync) ...[
            const SizedBox(height: PeopleSpacing.sm),
            _Notice(
              icon: Icons.cloud_upload_outlined,
              text: l10n.homeSavedWaitingSync,
              tone: BadgeTone.warning,
              onTap: onOpenSync,
            ),
          ],
          if (today.failedCount > 0) ...[
            const SizedBox(height: PeopleSpacing.sm),
            _Notice(
              icon: Icons.error_outline_rounded,
              text: l10n.homeFailedActions(today.failedCount),
              tone: BadgeTone.error,
              onTap: onOpenSync,
            ),
          ],
          if (today.server.isStale) ...[
            const SizedBox(height: PeopleSpacing.sm),
            Text(
              l10n.homeStaleSchedule,
              style: t.bodySmall!.copyWith(color: c.textMuted),
            ),
          ],
          const SizedBox(height: PeopleSpacing.md),
          _Action(
            today: today,
            onCheckIn: onCheckIn,
            onCheckOut: onCheckOut,
            onOpenRequests: onOpenRequests,
          ),
        ],
      ),
    );
  }
}

class _HeroView {
  const _HeroView({
    required this.icon,
    required this.tint,
    required this.title,
    required this.subtitle,
    this.badge,
  });

  final IconData icon;
  final Color tint;
  final String title;
  final String subtitle;
  final Widget? badge;

  static _HeroView of(BuildContext context, EffectiveToday today) {
    final c = context.colors;
    final l10n = context.l10n;
    final s = today.schedule;
    final site = s?.location?.name;
    final start = s?.startTime;
    final shiftLine = [
      if (start != null) l10n.homeShiftStarts(start),
      ?site,
    ].join(' · ');

    switch (today.state) {
      case TodayState.notCheckedIn:
        return _HeroView(
          icon: Icons.error_outline_rounded,
          tint: c.errorText,
          title: l10n.homeNotCheckedIn,
          subtitle: shiftLine.isEmpty ? l10n.homeReadyWhenYouAre : shiftLine,
        );
      case TodayState.checkedIn:
        return _HeroView(
          icon: Icons.check_circle_rounded,
          tint: c.successText,
          title: l10n.homeCheckedInTitle,
          subtitle: '',
          badge: StatusBadge(
            label: l10n.homeBadgeWorking,
            tone: BadgeTone.success,
            icon: Icons.circle,
          ),
        );
      case TodayState.checkedOut:
        final worked = today.elapsedAt(DateTime.now().toUtc());
        final inT = today.checkInAt?.hhmm ?? '--:--';
        final outT = today.checkOutAt?.hhmm ?? '--:--';
        return _HeroView(
          icon: Icons.task_alt_rounded,
          tint: c.successText,
          title: l10n.homeCheckedOutTitle,
          subtitle: worked == null
              ? '$inT - $outT'
              : l10n.homeCheckedOutWorked(
                  inT,
                  outT,
                  formatDurationL10n(l10n, worked.inMinutes),
                ),
          badge: StatusBadge(label: l10n.commonDone, tone: BadgeTone.success),
        );
      case TodayState.pendingReviewOpen:
      case TodayState.pendingReview:
        return _HeroView(
          icon: Icons.hourglass_top_rounded,
          tint: c.warningText,
          title: l10n.homeWaitingReview,
          subtitle: l10n.homeWaitingReviewMsg,
          badge: StatusBadge(
            label: l10n.homeBadgePending,
            tone: BadgeTone.warning,
          ),
        );
      case TodayState.correctionPending:
        return _HeroView(
          icon: Icons.edit_calendar_outlined,
          tint: c.warningText,
          title: l10n.homeCorrectionPending,
          subtitle: l10n.homeCorrectionPendingMsg,
          badge: StatusBadge(
            label: l10n.homeBadgePending,
            tone: BadgeTone.warning,
          ),
        );
      case TodayState.rejected:
        return _HeroView(
          icon: Icons.cancel_outlined,
          tint: c.errorText,
          title: l10n.homeRejectedTitle,
          subtitle: l10n.homeRejectedMsg,
          badge: StatusBadge(
            label: l10n.homeBadgeRejected,
            tone: BadgeTone.error,
          ),
        );
      case TodayState.notWorkingDay:
        final holiday = today.server.holidayName;
        return _HeroView(
          icon: Icons.weekend_outlined,
          tint: c.textSecondary,
          title: holiday ?? l10n.homeNotWorkingDay,
          subtitle: l10n.homeDayOffMsg,
          badge: StatusBadge(
            label: l10n.homeBadgeDayOff,
            tone: BadgeTone.neutral,
          ),
        );
      case TodayState.onLeave:
        return _HeroView(
          icon: Icons.beach_access_rounded,
          tint: c.primaryText,
          title: l10n.homeOnLeaveTitle,
          subtitle: l10n.homeOnLeaveMsg,
          badge: StatusBadge(
            label: l10n.homeBadgeOnLeave,
            tone: BadgeTone.primary,
          ),
        );
      case TodayState.noSchedule:
        return _HeroView(
          icon: Icons.event_busy_outlined,
          tint: c.textSecondary,
          title: l10n.homeNoScheduleTitle,
          subtitle: l10n.homeNoScheduleMsg,
          badge: StatusBadge(
            label: l10n.homeBadgeNoShift,
            tone: BadgeTone.neutral,
          ),
        );
      case TodayState.blocked:
        final terminated = today.server.blockedReason == 'EMPLOYEE_TERMINATED';
        return _HeroView(
          icon: Icons.block_rounded,
          tint: c.errorText,
          title: l10n.homeBlockedTitle,
          subtitle: terminated
              ? l10n.homeBlockedTerminatedMsg
              : l10n.homeBlockedInactiveMsg,
          badge: StatusBadge(
            label: terminated
                ? l10n.homeBadgeTerminated
                : l10n.homeBadgeInactive,
            tone: BadgeTone.error,
          ),
        );
    }
  }
}

class _StatusBody extends StatelessWidget {
  const _StatusBody({required this.view});
  final _HeroView view;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    final c = context.colors;
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        IconTile(
          icon: view.icon,
          color: view.tint,
          size: 40,
          iconSize: 22,
          circle: true,
        ),
        const SizedBox(width: PeopleSpacing.sm),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                view.title,
                style: t.titleMedium!.copyWith(color: view.tint),
              ),
              if (view.subtitle.isNotEmpty) ...[
                const SizedBox(height: 2),
                Text(
                  view.subtitle,
                  style: t.bodyMedium!.copyWith(color: c.textSecondary),
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

/// Checked-in body: live timer + where/when.
class _WorkingBody extends StatefulWidget {
  const _WorkingBody({required this.today, this.now});
  final EffectiveToday today;
  final DateTime Function()? now;

  @override
  State<_WorkingBody> createState() => _WorkingBodyState();
}

class _WorkingBodyState extends State<_WorkingBody> {
  Timer? _timer;
  late DateTime _now = _clock();

  DateTime _clock() => (widget.now ?? DateTime.now)().toUtc();

  @override
  void initState() {
    super.initState();
    _timer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) setState(() => _now = _clock());
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final l10n = context.l10n;
    final t = Theme.of(context).textTheme;
    final today = widget.today;
    final elapsed = today.elapsedAt(_now) ?? Duration.zero;
    final site = today.schedule?.location?.name;
    final since = today.checkInAt?.hhmm;
    final event = today.server.attendance?.checkIn;
    final distance = event?.distanceMeters;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Icon(Icons.check_circle_rounded, color: c.successText, size: 20),
            const SizedBox(width: PeopleSpacing.xs),
            Text(
              l10n.homeCheckedInTitle,
              style: t.titleMedium!.copyWith(color: c.successText),
            ),
          ],
        ),
        const SizedBox(height: PeopleSpacing.xs),
        Semantics(
          label: l10n.homeTimeWorkedSemantic(
            formatDurationL10n(l10n, elapsed.inMinutes),
          ),
          excludeSemantics: true,
          child: Text(
            formatClock(elapsed),
            style: t.displayMedium!.copyWith(
              fontFeatures: const [FontFeature.tabularFigures()],
              letterSpacing: -0.5,
            ),
          ),
        ),
        const SizedBox(height: PeopleSpacing.xxs),
        Wrap(
          spacing: PeopleSpacing.sm,
          runSpacing: PeopleSpacing.xxs,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            if (since != null)
              Text(
                l10n.homeSinceTime(since),
                style: t.bodyMedium!.copyWith(color: c.textSecondary),
              ),
            if (site != null)
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(Icons.place_outlined, size: 16, color: c.textMuted),
                  const SizedBox(width: 2),
                  Text(
                    distance == null
                        ? site
                        : '$site · ${formatDistance(distance, locale: context.fmt.tag)}',
                    style: t.bodyMedium!.copyWith(color: c.textSecondary),
                  ),
                ],
              ),
            if (today.isLate)
              StatusBadge.late(
                label: today.lateIsEstimate
                    ? l10n.attendanceLateEstimate(
                        formatDurationL10n(l10n, today.lateMinutes),
                      )
                    : l10n.attendanceLate(
                        formatDurationL10n(l10n, today.lateMinutes),
                      ),
                dense: true,
              )
            else
              StatusBadge(
                label: l10n.attendanceOnTime,
                tone: BadgeTone.success,
                dense: true,
              ),
          ],
        ),
      ],
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice({
    required this.icon,
    required this.text,
    required this.tone,
    required this.onTap,
  });

  final IconData icon;
  final String text;
  final BadgeTone tone;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final (Color bg, Color fg) = tone == BadgeTone.error
        ? (c.errorSoft, c.errorText)
        : (c.warningSoft, c.warningText);
    return Semantics(
      container: true,
      button: true,
      label: text,
      excludeSemantics: true,
      child: Material(
        color: bg,
        borderRadius: PeopleRadii.inputRadius,
        child: InkWell(
          borderRadius: PeopleRadii.inputRadius,
          onTap: onTap,
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: kMinTap),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: PeopleSpacing.sm),
              child: Row(
                children: [
                  Icon(icon, size: 18, color: fg),
                  const SizedBox(width: PeopleSpacing.xs),
                  Expanded(
                    child: Text(
                      text,
                      style: Theme.of(context).textTheme.bodySmall!
                          .copyWith(color: fg),
                    ),
                  ),
                  Icon(Icons.chevron_right_rounded, size: 18, color: fg),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _Action extends StatelessWidget {
  const _Action({
    required this.today,
    required this.onCheckIn,
    required this.onCheckOut,
    required this.onOpenRequests,
  });

  final EffectiveToday today;
  final VoidCallback onCheckIn;
  final VoidCallback onCheckOut;
  final VoidCallback onOpenRequests;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    switch (today.state) {
      case TodayState.notCheckedIn:
        return PrimaryButton(
          label: l10n.homeCheckIn,
          icon: Icons.login_rounded,
          onPressed: today.canCheckIn ? onCheckIn : null,
        );
      case TodayState.checkedIn:
        return PrimaryButton(
          label: l10n.homeCheckOut,
          icon: Icons.logout_rounded,
          onPressed: today.canCheckOut ? onCheckOut : null,
        );
      case TodayState.pendingReviewOpen when today.canCheckOut:
        return PrimaryButton(
          label: l10n.homeCheckOut,
          icon: Icons.logout_rounded,
          onPressed: onCheckOut,
        );
      case TodayState.correctionPending:
      case TodayState.pendingReview:
      case TodayState.pendingReviewOpen:
      case TodayState.rejected:
        return OutlineButton(
          label: l10n.homeViewRequests,
          icon: Icons.assignment_outlined,
          onPressed: onOpenRequests,
        );
      case TodayState.checkedOut:
      case TodayState.notWorkingDay:
      case TodayState.noSchedule:
      case TodayState.onLeave:
      case TodayState.blocked:
        return const SizedBox.shrink();
    }
  }
}
