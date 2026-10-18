import 'package:flutter/material.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Slim banner shown while the device is offline.
class OfflineBanner extends StatelessWidget {
  const OfflineBanner({super.key, this.pendingCount = 0});

  final int pendingCount;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final l10n = context.l10n;
    final suffix = pendingCount > 0
        ? ' ${l10n.commonOfflineWaiting(pendingCount)}'
        : ' ${l10n.commonOfflineSaved}';
    return Semantics(
      liveRegion: true,
      container: true,
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(
          horizontal: PeopleSpacing.page,
          vertical: PeopleSpacing.xs + 2,
        ),
        color: c.warningSoft,
        child: Row(
          children: [
            Icon(Icons.wifi_off_rounded, size: 18, color: c.warningText),
            const SizedBox(width: PeopleSpacing.xs),
            Expanded(
              child: Text.rich(
                TextSpan(
                  children: [
                    TextSpan(
                      text: l10n.commonOfflineTitle,
                      style: const TextStyle(fontWeight: FontWeight.w600),
                    ),
                    TextSpan(text: suffix),
                  ],
                ),
                style: t.bodySmall!.copyWith(color: c.warningText),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

enum SyncVisual { synced, pending, syncing, failed }

/// Compact sync indicator: icon + text (never colour alone).
class SyncStatusChip extends StatelessWidget {
  const SyncStatusChip({
    super.key,
    required this.visual,
    required this.label,
    this.onTap,
  });

  final SyncVisual visual;
  final String label;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final (IconData icon, Color bg, Color fg) = switch (visual) {
      SyncVisual.synced => (
        Icons.cloud_done_outlined,
        c.successSoft,
        c.successText,
      ),
      SyncVisual.pending => (
        Icons.cloud_upload_outlined,
        c.warningSoft,
        c.warningText,
      ),
      SyncVisual.syncing => (Icons.sync_rounded, c.primarySoft, c.primaryText),
      SyncVisual.failed => (
        Icons.error_outline_rounded,
        c.errorSoft,
        c.errorText,
      ),
    };
    return Semantics(
      container: true,
      button: onTap != null,
      label: context.l10n.commonSyncStatus(label),
      excludeSemantics: true,
      child: Material(
        color: bg,
        shape: const StadiumBorder(),
        child: InkWell(
          customBorder: const StadiumBorder(),
          onTap: onTap,
          child: ConstrainedBox(
            constraints: BoxConstraints(minHeight: onTap == null ? 0 : kMinTap),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  visual == SyncVisual.syncing
                      ? SizedBox(
                          width: 14,
                          height: 14,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            color: fg,
                          ),
                        )
                      : Icon(icon, size: 15, color: fg),
                  const SizedBox(width: 6),
                  Flexible(
                    child: Text(
                      label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.labelMedium!
                          .copyWith(color: fg, fontWeight: FontWeight.w600),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
