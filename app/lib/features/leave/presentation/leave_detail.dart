import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/app/router.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/utils/responsive.dart';
import 'package:peopleos/core/utils/zoned_time.dart';
import 'package:peopleos/core/widgets/app_card.dart';
import 'package:peopleos/core/widgets/app_top_bar.dart';
import 'package:peopleos/core/widgets/buttons.dart';
import 'package:peopleos/core/widgets/list_widgets.dart';
import 'package:peopleos/core/widgets/sheets_dialogs.dart';
import 'package:peopleos/core/widgets/skeleton.dart';
import 'package:peopleos/core/widgets/state_views.dart';
import 'package:peopleos/features/leave/domain/leave_errors.dart';
import 'package:peopleos/features/leave/domain/leave_models.dart';
import 'package:peopleos/features/leave/presentation/leave_providers.dart';
import 'package:peopleos/features/leave/presentation/widgets/leave_widgets.dart';
import 'package:peopleos/features/requests/presentation/form_widgets.dart';
import 'package:peopleos/features/requests/presentation/requests_screen.dart';
import 'package:peopleos/l10n/l10n.dart';

Future<void> showLeaveDetail(
  BuildContext context,
  WidgetRef ref,
  LeaveRequest request,
) async {
  if (context.isWide) {
    final pane = ref.read(requestsPaneProvider.notifier);
    pane.show(
      Column(
        key: ValueKey('leave${request.id}'),
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            request.type.name,
            style: Theme.of(context).textTheme.titleLarge,
          ),
          const SizedBox(height: PeopleSpacing.md),
          LeaveDetailBody(request: request, onFinished: pane.clear),
        ],
      ),
    );
    return;
  }
  await showAppSheet<void>(
    context,
    title: request.type.name,
    builder: (sheetContext) => LeaveDetailBody(
      request: request,
      onFinished: () => Navigator.of(sheetContext).pop(),
    ),
  );
}

/// Deep-link target (e.g. from a notification).
class LeaveDetailScreen extends ConsumerWidget {
  const LeaveDetailScreen({super.key, required this.id});

  final int id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(leaveDetailProvider(id));
    return SubPage(
      title: context.l10n.leaveDetailTitle,
      child: detail.when(
        loading: () => const SkeletonList(count: 3, itemHeight: 96),
        error: (e, _) => ErrorState(
          error: e,
          onRetry: () => ref.invalidate(leaveDetailProvider(id)),
        ),
        data: (r) => ListView(
          padding: const EdgeInsets.all(PeopleSpacing.page),
          children: [
            Text(r.type.name, style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: PeopleSpacing.md),
            LeaveDetailBody(request: r, onFinished: () => context.pop()),
          ],
        ),
      ),
    );
  }
}

class LeaveDetailBody extends ConsumerStatefulWidget {
  const LeaveDetailBody({
    super.key,
    required this.request,
    required this.onFinished,
  });

  final LeaveRequest request;

  /// Called after a successful action (closes the sheet or page).
  final VoidCallback onFinished;

  @override
  ConsumerState<LeaveDetailBody> createState() => _LeaveDetailBodyState();
}

class _LeaveDetailBodyState extends ConsumerState<LeaveDetailBody> {
  bool _busy = false;
  String? _error;

  DateTime get _today {
    final n = DateTime.now();
    return DateTime(n.year, n.month, n.day);
  }

  Future<void> _run(
    Future<LeaveRequest> Function() action,
    String successMessage,
  ) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await action();
      invalidateLeave(ref);
      if (!mounted) return;
      widget.onFinished();
      showAppSnack(context, successMessage);
    } on AppException catch (e) {
      invalidateLeave(ref);
      if (mounted) {
        setState(() {
          _busy = false;
          _error = describeLeaveError(e, context.l10n);
        });
      }
    }
  }

  Future<void> _cancel() async {
    final r = widget.request;
    final l10n = context.l10n;
    final ok = await confirmDialog(
      context,
      title: r.status == LeaveStatus.draft
          ? l10n.leaveConfirmDiscardTitle
          : l10n.leaveConfirmCancelTitle,
      message: r.status == LeaveStatus.approved
          ? l10n.leaveConfirmCancelApprovedMessage
          : l10n.leaveConfirmWithdrawMessage,
      confirmLabel: r.status == LeaveStatus.draft
          ? l10n.leaveConfirmDiscard
          : l10n.leaveConfirmCancelLeave,
      cancelLabel: l10n.leaveKeepIt,
      destructive: true,
    );
    if (!ok) return;
    await _run(
      () => ref.read(leaveRepositoryProvider).cancel(r.id),
      r.status == LeaveStatus.draft
          ? l10n.leaveDraftDiscarded
          : l10n.leaveCancelledSnack,
    );
  }

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final l10n = context.l10n;
    final fmt = context.fmt;
    final r = widget.request;
    final isDraft = r.status == LeaveStatus.draft;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            leaveStatusBadge(r.status, l10n),
            const SizedBox(width: PeopleSpacing.xs),
            if (!r.type.isPaid) Text(l10n.leaveUnpaid, style: t.labelMedium),
          ],
        ),
        const SizedBox(height: PeopleSpacing.md),
        AppCard(
          shadow: false,
          padding: EdgeInsets.zero,
          child: Column(
            children: [
              KeyValueRow(
                label: l10n.leaveDetailDates,
                value: formatLeaveRange(r, fmt.tag),
              ),
              const RowDivider(),
              KeyValueRow(
                label: l10n.leaveDetailDuration,
                value: leaveDuration(r, l10n),
              ),
              const RowDivider(),
              KeyValueRow(label: l10n.leaveDetailType, value: r.type.name),
              if (r.workingDates.isNotEmpty) ...[
                const RowDivider(),
                KeyValueRow(
                  label: l10n.leaveDetailWorkingDays,
                  value: r.workingDates.map(fmt.weekdayShort).join(', '),
                ),
              ],
            ],
          ),
        ),
        if (r.reason != null && r.reason!.isNotEmpty) ...[
          const SizedBox(height: PeopleSpacing.md),
          Text(l10n.leaveDetailReason, style: t.titleSmall),
          const SizedBox(height: 2),
          Text(
            r.reason!,
            style: t.bodyMedium!.copyWith(color: c.textSecondary),
          ),
        ],
        if (r.status == LeaveStatus.rejected &&
            (r.reviewNote ?? '').isNotEmpty) ...[
          const SizedBox(height: PeopleSpacing.md),
          _Callout(
            icon: Icons.info_outline_rounded,
            title: l10n.leaveDetailRejectionReason,
            text: r.reviewNote!,
          ),
        ],
        const SizedBox(height: PeopleSpacing.lg),
        Text(l10n.leaveDetailStatus, style: t.titleSmall),
        const SizedBox(height: PeopleSpacing.xs),
        _Timeline(request: r),
        if (_error != null) ...[
          const SizedBox(height: PeopleSpacing.md),
          FormErrorBanner(message: _error!),
        ],
        if (isDraft) ...[
          const SizedBox(height: PeopleSpacing.lg),
          PrimaryButton(
            label: l10n.leaveSubmitRequest,
            icon: Icons.send_rounded,
            loading: _busy,
            onPressed: _busy
                ? null
                : () => _run(
                    () => ref.read(leaveRepositoryProvider).submit(r.id),
                    l10n.leaveSubmittedSnack,
                  ),
          ),
          const SizedBox(height: PeopleSpacing.xs),
          OutlineButton(
            label: l10n.leaveEditDraft,
            icon: Icons.edit_outlined,
            onPressed: _busy
                ? null
                : () {
                    final router = GoRouter.of(context);
                    widget.onFinished();
                    router.push(Routes.newLeave, extra: r);
                  },
          ),
        ],
        if (r.canCancel(_today)) ...[
          const SizedBox(height: PeopleSpacing.xs),
          DangerButton(
            label: isDraft
                ? l10n.leaveDiscardDraft
                : r.status == LeaveStatus.approved
                ? l10n.leaveCancelApproved
                : l10n.leaveCancelRequest,
            icon: Icons.close_rounded,
            loading: _busy && !isDraft,
            onPressed: _busy ? null : _cancel,
          ),
        ],
      ],
    );
  }
}

class _Callout extends StatelessWidget {
  const _Callout({required this.icon, required this.title, required this.text});

  final IconData icon;
  final String title;
  final String text;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    return Container(
      padding: const EdgeInsets.all(PeopleSpacing.sm + 2),
      decoration: BoxDecoration(
        color: c.errorSoft,
        borderRadius: PeopleRadii.inputRadius,
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 20, color: c.errorText),
          const SizedBox(width: PeopleSpacing.xs),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: t.titleSmall!.copyWith(color: c.errorText)),
                Text(text, style: t.bodyMedium!.copyWith(color: c.textPrimary)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Step {
  const _Step(this.label, this.when, this.state, {this.note});
  final String label;
  final ZonedTime? when;
  final _StepState state;
  final String? note;
}

enum _StepState { done, current, failed }

/// Draft → Submitted → Decision (or Cancelled), icon + text per step.
class _Timeline extends StatelessWidget {
  const _Timeline({required this.request});
  final LeaveRequest request;

  List<_Step> _steps(AppLocalizations l10n) {
    final r = request;
    final reviewer = r.reviewerName;
    final approved = reviewer == null
        ? l10n.leaveStepApproved
        : l10n.leaveStepApprovedBy(reviewer);
    final steps = <_Step>[
      _Step(l10n.leaveStepCreated, r.createdAt, _StepState.done),
    ];
    if (r.submittedAt != null) {
      steps.add(_Step(l10n.leaveStepSubmitted, r.submittedAt, _StepState.done));
    }
    switch (r.status) {
      case LeaveStatus.draft:
        steps.add(_Step(l10n.leaveStepDraft, null, _StepState.current));
      case LeaveStatus.pending:
        steps.add(_Step(l10n.leaveStepWaiting, null, _StepState.current));
      case LeaveStatus.approved:
        steps.add(
          _Step(approved, r.reviewedAt, _StepState.done, note: r.reviewNote),
        );
      case LeaveStatus.rejected:
        steps.add(
          _Step(
            reviewer == null
                ? l10n.leaveStepRejected
                : l10n.leaveStepRejectedBy(reviewer),
            r.reviewedAt,
            _StepState.failed,
          ),
        );
      case LeaveStatus.cancelled:
        if (r.reviewedAt != null) {
          steps.add(_Step(approved, r.reviewedAt, _StepState.done));
        }
        steps.add(
          _Step(
            l10n.leaveStepCancelled,
            r.cancelledAt,
            _StepState.failed,
            note: r.cancelReason,
          ),
        );
    }
    return steps;
  }

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final fmt = context.fmt;
    return Column(
      children: [
        for (final s in _steps(context.l10n))
          Padding(
            padding: const EdgeInsets.only(bottom: PeopleSpacing.xs),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(
                  switch (s.state) {
                    _StepState.done => Icons.check_circle_rounded,
                    _StepState.current => Icons.radio_button_checked_rounded,
                    _StepState.failed => Icons.cancel_rounded,
                  },
                  size: 20,
                  color: switch (s.state) {
                    _StepState.done => c.success,
                    _StepState.current => c.warning,
                    _StepState.failed => c.errorText,
                  },
                ),
                const SizedBox(width: PeopleSpacing.xs),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(s.label, style: t.bodyMedium),
                      if (s.when != null)
                        Text(
                          '${fmt.shortDate(s.when!.wall)} · ${s.when!.hhmm}',
                          style: t.bodySmall!.copyWith(color: c.textMuted),
                        ),
                      if (s.note != null && s.note!.isNotEmpty)
                        Text(
                          '"${s.note!}"',
                          style: t.bodySmall!.copyWith(color: c.textSecondary),
                        ),
                    ],
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }
}
