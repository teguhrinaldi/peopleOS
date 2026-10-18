import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/utils/responsive.dart';
import 'package:peopleos/core/widgets/app_card.dart';
import 'package:peopleos/core/widgets/buttons.dart';
import 'package:peopleos/core/widgets/list_widgets.dart';
import 'package:peopleos/core/widgets/sheets_dialogs.dart';
import 'package:peopleos/features/requests/domain/request_models.dart';
import 'package:peopleos/features/requests/presentation/requests_providers.dart';
import 'package:peopleos/features/requests/presentation/requests_screen.dart';
import 'package:peopleos/l10n/l10n.dart';

Future<void> showCorrectionDetail(
  BuildContext context,
  WidgetRef ref,
  CorrectionRequest r,
) {
  final l10n = context.l10n;
  final fmt = context.fmt;
  Widget body(VoidCallback done) => _DetailBody(
    onFinished: done,
    status: r.status,
    rows: [
      (l10n.requestsDetailWorkDate, fmt.weekdayLong(r.workDate)),
      (l10n.requestsDetailCheckIn, r.requestedCheckInAt?.hhmm ?? '-'),
      (l10n.requestsDetailCheckOut, r.requestedCheckOutAt?.hhmm ?? '-'),
      if (r.createdAt != null)
        (l10n.requestsDetailSubmitted, fmt.shortDate(r.createdAt!.wall)),
    ],
    reason: r.reason,
    reviewNote: r.reviewNote,
    cancelTitle: l10n.requestsCorrectionCancelTitle,
    onCancel: (ref) =>
        ref.read(requestsRepositoryProvider).cancelCorrection(r.id),
    onChanged: (ref) => ref.invalidate(correctionsProvider),
  );
  return _present(
    context,
    ref,
    l10n.requestsCorrectionDetailTitle,
    'c${r.id}',
    body,
  );
}

Future<void> showOvertimeDetail(
  BuildContext context,
  WidgetRef ref,
  OvertimeRequest r,
) {
  final l10n = context.l10n;
  final fmt = context.fmt;
  Widget body(VoidCallback done) => _DetailBody(
    onFinished: done,
    status: r.status,
    rows: [
      (l10n.requestsDetailWorkDate, fmt.weekdayLong(r.workDate)),
      (
        l10n.requestsDetailTime,
        l10n.requestsTimeRange(
          r.startAt?.hhmm ?? '--:--',
          r.endAt?.hhmm ?? '--:--',
        ),
      ),
      (
        l10n.requestsDetailRequested,
        formatDurationL10n(l10n, r.requestedMinutes),
      ),
      if (r.approvedMinutes != null)
        (
          l10n.requestsDetailApproved,
          formatDurationL10n(l10n, r.approvedMinutes!),
        ),
    ],
    reason: r.reason,
    reviewNote: r.reviewNote,
    cancelTitle: l10n.requestsOvertimeCancelTitle,
    onCancel: (ref) =>
        ref.read(requestsRepositoryProvider).cancelOvertime(r.id),
    onChanged: (ref) => ref.invalidate(overtimeProvider),
  );
  return _present(
    context,
    ref,
    l10n.requestsOvertimeDetailTitle,
    'o${r.id}',
    body,
  );
}

/// Tablet: right-hand pane. Phone: bottom sheet.
Future<void> _present(
  BuildContext context,
  WidgetRef ref,
  String title,
  String key,
  Widget Function(VoidCallback done) body,
) async {
  if (context.isWide) {
    ref
        .read(requestsPaneProvider.notifier)
        .show(
          Column(
            key: ValueKey(key),
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(title, style: Theme.of(context).textTheme.titleLarge),
              const SizedBox(height: PeopleSpacing.md),
              body(ref.read(requestsPaneProvider.notifier).clear),
            ],
          ),
        );
    return;
  }
  await showAppSheet<void>(
    context,
    title: title,
    builder: (sheetContext) => body(() => Navigator.of(sheetContext).pop()),
  );
}

class _DetailBody extends ConsumerStatefulWidget {
  const _DetailBody({
    required this.onFinished,
    required this.status,
    required this.rows,
    required this.reason,
    required this.reviewNote,
    required this.cancelTitle,
    required this.onCancel,
    required this.onChanged,
  });

  final VoidCallback onFinished;
  final RequestStatus status;
  final List<(String, String)> rows;
  final String reason;
  final String? reviewNote;
  final String cancelTitle;
  final Future<Object?> Function(WidgetRef ref) onCancel;
  final void Function(WidgetRef ref) onChanged;

  @override
  ConsumerState<_DetailBody> createState() => _DetailBodyState();
}

class _DetailBodyState extends ConsumerState<_DetailBody> {
  bool _busy = false;
  String? _error;

  Future<void> _cancel() async {
    final l10n = context.l10n;
    final ok = await confirmDialog(
      context,
      title: widget.cancelTitle,
      message: l10n.requestsCancelMessage,
      confirmLabel: l10n.requestsCancelRequest,
      cancelLabel: l10n.requestsKeepIt,
      destructive: true,
    );
    if (!ok || !mounted) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.onCancel(ref);
      widget.onChanged(ref);
      if (mounted) {
        widget.onFinished();
        showAppSnack(context, l10n.requestsCancelledSnack);
      }
    } on AppException catch (e) {
      // e.g. REQUEST_NOT_PENDING: someone reviewed it meanwhile; refresh.
      widget.onChanged(ref);
      if (mounted) {
        setState(() {
          _busy = false;
          _error = describeRequestError(e, l10n);
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final l10n = context.l10n;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Align(
          alignment: Alignment.centerLeft,
          child: requestBadge(widget.status, l10n),
        ),
        const SizedBox(height: PeopleSpacing.md),
        AppCard(
          shadow: false,
          padding: EdgeInsets.zero,
          child: Column(
            children: [
              for (var i = 0; i < widget.rows.length; i++) ...[
                if (i > 0) const RowDivider(),
                KeyValueRow(label: widget.rows[i].$1, value: widget.rows[i].$2),
              ],
            ],
          ),
        ),
        const SizedBox(height: PeopleSpacing.md),
        Text(l10n.requestsReason, style: t.titleSmall),
        const SizedBox(height: 2),
        Text(
          widget.reason,
          style: t.bodyMedium!.copyWith(color: c.textSecondary),
        ),
        if (widget.reviewNote != null && widget.reviewNote!.isNotEmpty) ...[
          const SizedBox(height: PeopleSpacing.md),
          Text(l10n.requestsReviewerNote, style: t.titleSmall),
          const SizedBox(height: 2),
          Text(
            widget.reviewNote!,
            style: t.bodyMedium!.copyWith(color: c.textSecondary),
          ),
        ],
        if (_error != null) ...[
          const SizedBox(height: PeopleSpacing.md),
          Text(_error!, style: t.bodyMedium!.copyWith(color: c.errorText)),
        ],
        if (widget.status == RequestStatus.pending) ...[
          const SizedBox(height: PeopleSpacing.lg),
          DangerButton(
            label: l10n.requestsCancelRequest,
            icon: Icons.close_rounded,
            loading: _busy,
            onPressed: _cancel,
          ),
        ],
      ],
    );
  }
}
