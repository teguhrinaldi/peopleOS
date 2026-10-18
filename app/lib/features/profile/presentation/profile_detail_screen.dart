import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/widgets/app_card.dart';
import 'package:peopleos/core/widgets/app_top_bar.dart';
import 'package:peopleos/core/widgets/list_widgets.dart';
import 'package:peopleos/features/auth/presentation/auth_controller.dart';
import 'package:peopleos/features/profile/presentation/profile_labels.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Read-only profile sections: personal, employment, work.
class ProfileDetailScreen extends ConsumerWidget {
  const ProfileDetailScreen({super.key, required this.kind});

  final String kind;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(authControllerProvider);
    final e = state.employee;
    final company = state.session?.company;
    if (e == null) return const SizedBox.shrink();

    String date(DateTime? d) => d == null ? '' : context.fmt.shortDate(d);
    final l = context.l10n;

    final (String title, List<(String, String)> rows) = switch (kind) {
      'personal' => (
        l.profilePersonalInfo,
        [
          (l.profileFullName, e.fullName),
          (l.profileGender, genderLabel(l, e.gender)),
          (l.profileDateOfBirth, date(e.dateOfBirth)),
          (l.profilePhone, e.phone ?? ''),
          (l.profileEmail, e.email ?? ''),
          (l.profileAddress, e.address ?? ''),
        ],
      ),
      'employment' => (
        l.profileEmployment,
        [
          (l.profileEmployeeNumber, e.employeeNumber),
          (l.profileDepartment, e.department?.name ?? ''),
          (l.profilePosition, e.position?.name ?? ''),
          (l.profileManager, e.manager?.name ?? ''),
          (l.profileJoinDate, date(e.joinDate)),
          (l.profileEmploymentType, employmentTypeLabel(l, e.employmentType)),
          (l.profileStatus, employeeStatusLabel(l, e.status)),
        ],
      ),
      _ => (
        l.profileWorkInfo,
        [
          (l.profileBranch, e.branch?.name ?? ''),
          (l.profileWorkLocation, e.workLocation?.name ?? ''),
          (l.profileShift, e.shift?.name ?? ''),
          (l.profileShiftHours, e.shift?.label ?? ''),
          (l.profileCompany, company?.name ?? ''),
          (l.profileTimezone, company?.timezone ?? ''),
        ],
      ),
    };

    return SubPage(
      title: title,
      child: ListView(
        padding: const EdgeInsets.all(PeopleSpacing.page),
        children: [
          AppCard(
            padding: EdgeInsets.zero,
            child: Column(
              children: [
                for (var i = 0; i < rows.length; i++) ...[
                  if (i > 0) const RowDivider(),
                  KeyValueRow(label: rows[i].$1, value: rows[i].$2),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}
