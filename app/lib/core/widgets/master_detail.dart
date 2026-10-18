import 'package:flutter/material.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/widgets/state_views.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Tablet layout: list on the left, detail on the right, separated by a
/// hairline. [detail] is null until the user picks something.
class MasterDetail extends StatelessWidget {
  const MasterDetail({
    super.key,
    required this.master,
    required this.detail,
    this.masterWidth = 400,
    this.emptyTitle,
    this.emptyIcon = Icons.touch_app_outlined,
  });

  final Widget master;
  final Widget? detail;
  final double masterWidth;
  final String? emptyTitle;
  final IconData emptyIcon;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    return Row(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SizedBox(width: masterWidth, child: master),
        VerticalDivider(width: 1, color: c.border),
        Expanded(
          child: detail == null
              ? EmptyState(
                  title: emptyTitle ?? context.l10n.commonSelectItem,
                  icon: emptyIcon,
                )
              : Align(
                  alignment: Alignment.topCenter,
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 640),
                    child: SingleChildScrollView(
                      padding: const EdgeInsets.all(PeopleSpacing.xl),
                      child: detail,
                    ),
                  ),
                ),
        ),
      ],
    );
  }
}
