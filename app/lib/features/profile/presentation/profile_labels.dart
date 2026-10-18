import 'package:peopleos/core/storage/app_settings.dart';
import 'package:peopleos/l10n/l10n.dart';

String _cap(String s) => s.isEmpty ? s : s[0].toUpperCase() + s.substring(1);

/// Employee status word (ACTIVE / PENDING / INACTIVE / TERMINATED). Unknown
/// values are shown as-is, capitalised.
String employeeStatusLabel(AppLocalizations l10n, String status) {
  switch (status.toUpperCase()) {
    case 'ACTIVE':
      return l10n.profileStatusActive;
    case 'PENDING':
      return l10n.profileStatusPending;
    case 'TERMINATED':
      return l10n.profileStatusTerminated;
    case 'INACTIVE':
      return l10n.profileStatusInactive;
  }
  return _cap(status.toLowerCase());
}

String genderLabel(AppLocalizations l10n, String? gender) {
  if (gender == null || gender.isEmpty) return '';
  switch (gender.toLowerCase()) {
    case 'male':
      return l10n.profileGenderMale;
    case 'female':
      return l10n.profileGenderFemale;
  }
  return _cap(gender);
}

String employmentTypeLabel(AppLocalizations l10n, String? type) {
  if (type == null || type.isEmpty) return '';
  switch (type.toLowerCase().replaceAll(RegExp(r'[\s_-]'), '')) {
    case 'permanent':
      return l10n.profileTypePermanent;
    case 'contract':
      return l10n.profileTypeContract;
    case 'probation':
      return l10n.profileTypeProbation;
    case 'internship':
    case 'intern':
      return l10n.profileTypeInternship;
    case 'freelance':
      return l10n.profileTypeFreelance;
    case 'parttime':
      return l10n.profileTypePartTime;
  }
  return _cap(type);
}

String demoScenarioLabel(AppLocalizations l10n, DemoScenario s) => switch (s) {
  DemoScenario.normal => l10n.settingsScenarioNormal,
  DemoScenario.selfieRequired => l10n.settingsScenarioSelfie,
  DemoScenario.restDay => l10n.settingsScenarioRestDay,
  DemoScenario.noSchedule => l10n.settingsScenarioNoSchedule,
  DemoScenario.onLeave => l10n.settingsScenarioOnLeave,
  DemoScenario.terminated => l10n.settingsScenarioTerminated,
};
