import 'package:flutter/material.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/widgets/avatar.dart';
import 'package:peopleos/features/auth/domain/session.dart';
import 'package:peopleos/l10n/l10n.dart';

class HomeHeader extends StatelessWidget {
  const HomeHeader({
    super.key,
    required this.employee,
    required this.hour,
    required this.onBell,
    this.unread = 0,
  });

  final Employee employee;
  final int hour;
  final VoidCallback onBell;
  final int unread;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final l10n = context.l10n;
    final t = Theme.of(context).textTheme;
    final dept = employee.department?.name;
    return Row(
      children: [
        Avatar(name: employee.fullName, photoUrl: employee.photoUrl, size: 48),
        const SizedBox(width: PeopleSpacing.sm),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                greetingL10n(l10n, hour),
                style: t.bodySmall!.copyWith(color: c.textMuted),
              ),
              Semantics(
                header: true,
                child: Text(
                  employee.fullName,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: t.titleLarge,
                ),
              ),
              if (dept != null)
                Text(
                  l10n.homeDepartment(dept),
                  style: t.bodySmall!.copyWith(color: c.textMuted),
                ),
            ],
          ),
        ),
        Semantics(
          value: unread > 0 ? l10n.homeUnreadCount(unread) : null,
          child: Stack(
            clipBehavior: Clip.none,
            children: [
              IconButton(
                tooltip: l10n.homeNotifications,
                constraints: const BoxConstraints(
                  minWidth: kMinTap,
                  minHeight: kMinTap,
                ),
                onPressed: onBell,
                icon: Icon(
                  Icons.notifications_none_rounded,
                  size: 26,
                  color: c.textPrimary,
                ),
              ),
              if (unread > 0)
                Positioned(
                  right: 6,
                  top: 6,
                  child: IgnorePointer(
                    child: ExcludeSemantics(
                      child: Container(
                        constraints: const BoxConstraints(minWidth: 18),
                        height: 18,
                        padding: const EdgeInsets.symmetric(horizontal: 4),
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          color: c.error,
                          borderRadius: BorderRadius.circular(9),
                          border: Border.all(color: c.background, width: 2),
                        ),
                        child: Text(
                          unread > 9 ? '9+' : '$unread',
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 10,
                            fontWeight: FontWeight.w700,
                            height: 1.1,
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ],
    );
  }
}
