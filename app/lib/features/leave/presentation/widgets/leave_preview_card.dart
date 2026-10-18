import 'package:flutter/material.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/widgets/app_card.dart';
import 'package:peopleos/features/leave/domain/leave_models.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Live result of `POST /leave-requests/preview`: working days counted and
/// the balance that would remain. Server-validated, so policy problems
/// (notice, past dates, half-day rules) show up here before submitting.
class LeavePreviewCard extends StatelessWidget {
  const LeavePreviewCard({
    super.key,
    required this.ready,
    required this.loading,
    this.preview,
    this.error,
  });

  final bool ready;
  final bool loading;
  final LeavePreview? preview;
  final String? error;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final l10n = context.l10n;

    Widget body;
    if (!ready) {
      body = Text(
        l10n.leavePreviewIntro,
        style: t.bodyMedium!.copyWith(color: c.textMuted),
      );
    } else if (error != null) {
      body = Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.warning_amber_rounded, color: c.warningText, size: 20),
          const SizedBox(width: PeopleSpacing.xs),
          Expanded(
            child: Text(
              error!,
              style: t.bodyMedium!.copyWith(color: c.warningText),
            ),
          ),
        ],
      );
    } else if (preview == null) {
      body = Row(
        children: [
          const SizedBox(
            width: 16,
            height: 16,
            child: CircularProgressIndicator(strokeWidth: 2),
          ),
          const SizedBox(width: PeopleSpacing.xs),
          Text(
            l10n.leavePreviewCalculating,
            style: t.bodyMedium!.copyWith(color: c.textMuted),
          ),
        ],
      );
    } else {
      body = _Result(preview: preview!);
    }

    return Semantics(
      liveRegion: true,
      container: true,
      child: AppCard(
        shadow: false,
        color: c.surfaceMuted,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Text(l10n.leavePreviewSummary, style: t.titleSmall),
                const Spacer(),
                if (loading && preview != null)
                  const SizedBox(
                    width: 14,
                    height: 14,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  ),
              ],
            ),
            const SizedBox(height: PeopleSpacing.xs),
            body,
          ],
        ),
      ),
    );
  }
}

class _Result extends StatelessWidget {
  const _Result({required this.preview});
  final LeavePreview preview;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final l10n = context.l10n;
    final fmt = context.fmt;
    final p = preview;
    final balance = p.balance;
    final unlimited = balance == null || balance.unlimited;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          l10n.leavePreviewDaysOfLeave(formatDays(l10n, p.days)),
          style: t.headlineSmall,
        ),
        Text(
          l10n.leavePreviewNotCounted,
          style: t.bodySmall!.copyWith(color: c.textMuted),
        ),
        if (p.workingDates.isNotEmpty) ...[
          const SizedBox(height: PeopleSpacing.xs),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final d in p.workingDates.take(10))
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 8,
                    vertical: 3,
                  ),
                  decoration: BoxDecoration(
                    color: c.surface,
                    borderRadius: PeopleRadii.chipRadius,
                    border: Border.all(color: c.border),
                  ),
                  child: Text(fmt.chipDate(d), style: t.labelSmall),
                ),
              if (p.workingDates.length > 10)
                Text(
                  l10n.leavePreviewMore(p.workingDates.length - 10),
                  style: t.labelSmall,
                ),
            ],
          ),
        ],
        const SizedBox(height: PeopleSpacing.sm),
        const Divider(height: 1),
        const SizedBox(height: PeopleSpacing.sm),
        if (unlimited)
          _Line(
            icon: Icons.all_inclusive_rounded,
            color: c.textSecondary,
            text: l10n.leavePreviewUnlimited,
          )
        else if (p.exceedsBalance)
          _Line(
            icon: Icons.error_outline_rounded,
            color: c.errorText,
            text: l10n.leavePreviewNotEnough(
              formatDays(l10n, balance.available ?? 0),
              formatDays(l10n, p.days),
            ),
          )
        else
          _Line(
            icon: Icons.account_balance_wallet_outlined,
            color: c.successText,
            text: l10n.leavePreviewLeftAfter(
              formatDays(l10n, p.availableAfter ?? 0),
              formatDays(l10n, balance.available ?? 0),
            ),
          ),
      ],
    );
  }
}

class _Line extends StatelessWidget {
  const _Line({required this.icon, required this.color, required this.text});
  final IconData icon;
  final Color color;
  final String text;

  @override
  Widget build(BuildContext context) => Row(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Icon(icon, size: 18, color: color),
      const SizedBox(width: PeopleSpacing.xs),
      Expanded(
        child: Text(
          text,
          style: Theme.of(context).textTheme.bodyMedium!.copyWith(color: color),
        ),
      ),
    ],
  );
}
