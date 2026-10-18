import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/utils/responsive.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Sub-page header from the reference: back arrow + left-aligned title.
class AppTopBar extends StatelessWidget implements PreferredSizeWidget {
  const AppTopBar({
    super.key,
    required this.title,
    this.actions = const [],
    this.showBack = true,
    this.onBack,
  });

  final String title;
  final List<Widget> actions;
  final bool showBack;
  final VoidCallback? onBack;

  @override
  Size get preferredSize => const Size.fromHeight(64);

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    return SafeArea(
      bottom: false,
      child: SizedBox(
        height: 64,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: PeopleSpacing.xs),
          child: Row(
            children: [
              if (showBack)
                IconButton(
                  tooltip: context.l10n.commonBack,
                  constraints: const BoxConstraints(
                    minWidth: kMinTap,
                    minHeight: kMinTap,
                  ),
                  icon: Icon(Icons.arrow_back_rounded, color: c.textPrimary),
                  onPressed:
                      onBack ??
                      () {
                        if (context.canPop()) {
                          context.pop();
                        } else {
                          context.go('/home');
                        }
                      },
                )
              else
                const SizedBox(width: PeopleSpacing.md),
              Expanded(
                child: Semantics(
                  header: true,
                  child: Text(
                    title,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                ),
              ),
              ...actions,
            ],
          ),
        ),
      ),
    );
  }
}

/// Scaffold for pushed pages: top bar + width-capped body.
class SubPage extends StatelessWidget {
  const SubPage({
    super.key,
    required this.title,
    required this.child,
    this.actions = const [],
    this.bottom,
    this.maxWidth = Breakpoints.formMax + 120,
  });

  final String title;
  final Widget child;
  final List<Widget> actions;
  final Widget? bottom;
  final double maxWidth;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppTopBar(title: title, actions: actions),
      body: ContentWidth(maxWidth: maxWidth, child: child),
      bottomNavigationBar: bottom == null
          ? null
          : SafeArea(
              child: ContentWidth(
                maxWidth: maxWidth,
                shrinkHeight: true,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(
                    PeopleSpacing.page,
                    PeopleSpacing.xs,
                    PeopleSpacing.page,
                    PeopleSpacing.md,
                  ),
                  child: bottom,
                ),
              ),
            ),
    );
  }
}
