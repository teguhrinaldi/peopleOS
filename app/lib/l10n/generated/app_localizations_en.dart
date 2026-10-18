// ignore: unused_import
import 'package:intl/intl.dart' as intl;

import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for English (`en`).
class AppLocalizationsEn extends AppLocalizations {
  AppLocalizationsEn([String locale = 'en']) : super(locale);

  @override
  String get appName => 'PeopleOS';

  @override
  String get languageTitle => 'Language';

  @override
  String get languageEnglish => 'English';

  @override
  String get languageIndonesian => 'Bahasa Indonesia';

  @override
  String get languageSystem => 'Device default';

  @override
  String get languageSavedLocalOnly =>
      'Language changed on this device, but it could not be saved to your account.';

  @override
  String staleBanner(String age) {
    return 'Showing saved data from $age. Reconnect and pull to refresh.';
  }

  @override
  String get ageJustNow => 'just now';

  @override
  String ageMinutes(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count minutes ago',
      one: '1 minute ago',
    );
    return '$_temp0';
  }

  @override
  String ageHours(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count hours ago',
      one: '1 hour ago',
    );
    return '$_temp0';
  }

  @override
  String ageDays(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count days ago',
      one: '1 day ago',
    );
    return '$_temp0';
  }

  @override
  String durationHm(int h, int m) {
    return '${h}h ${m}m';
  }

  @override
  String durationH(int h) {
    return '${h}h';
  }

  @override
  String durationM(int m) {
    return '${m}m';
  }

  @override
  String get greetingMorning => 'Good morning';

  @override
  String get greetingAfternoon => 'Good afternoon';

  @override
  String get greetingEvening => 'Good evening';

  @override
  String get greetingNight => 'Good night';

  @override
  String daysOne(String n) {
    return '$n day';
  }

  @override
  String daysMany(String n) {
    return '$n days';
  }

  @override
  String attendanceLate(String duration) {
    return 'Late $duration';
  }

  @override
  String attendanceLateEstimate(String duration) {
    return 'Late $duration (estimate)';
  }

  @override
  String get attendanceOnTime => 'On time';

  @override
  String get attendanceHistoryTitle => 'Attendance History';

  @override
  String get attendanceRequestCorrection => 'Request correction';

  @override
  String get attendancePreviousMonth => 'Previous month';

  @override
  String get attendanceNextMonth => 'Next month';

  @override
  String get attendanceSelectDay => 'Select a day';

  @override
  String get attendanceEmptyTitle => 'No attendance yet';

  @override
  String attendanceEmptyMessage(String month) {
    return 'Nothing recorded for $month.';
  }

  @override
  String get attendanceStatPresent => 'Present';

  @override
  String get attendanceStatLate => 'Late';

  @override
  String get attendanceStatHours => 'Hours';

  @override
  String attendanceStatSemantic(String label, String value) {
    return '$label: $value';
  }

  @override
  String attendanceRowSemanticLate(String date, String times, int minutes) {
    String _temp0 = intl.Intl.pluralLogic(
      minutes,
      locale: localeName,
      other: 'late $minutes minutes',
      one: 'late 1 minute',
    );
    return '$date, $times, $_temp0';
  }

  @override
  String attendanceRowSemanticOnTime(String date, String times) {
    return '$date, $times, on time';
  }

  @override
  String get attendanceBadgeWorking => 'Working';

  @override
  String get attendanceBadgeCorrected => 'Corrected';

  @override
  String get attendanceBadgeCorrection => 'Correction';

  @override
  String get attendanceBadgeInReview => 'In review';

  @override
  String get attendanceBadgeRejected => 'Rejected';

  @override
  String get attendanceBadgePresent => 'Present';

  @override
  String get attendanceSavedOnDevice => 'Saved on this device';

  @override
  String get attendanceCouldNotSync => 'Could not sync';

  @override
  String get attendanceSyncFailed => 'Sync failed';

  @override
  String get attendancePendingSync => 'Pending sync';

  @override
  String get attendanceCheckTypeIn => 'Check in';

  @override
  String get attendanceCheckTypeOut => 'Check out';

  @override
  String attendanceLeftEarly(String duration) {
    return 'Left $duration early';
  }

  @override
  String get attendanceKvCheckIn => 'Check in';

  @override
  String get attendanceKvCheckOut => 'Check out';

  @override
  String get attendanceKvWorked => 'Worked';

  @override
  String get attendanceKvShift => 'Shift';

  @override
  String get attendanceKvLocation => 'Location';

  @override
  String get attendanceCheckInDetails => 'Check-in details';

  @override
  String get attendanceCheckOutDetails => 'Check-out details';

  @override
  String get attendanceReviewNote => 'Review note';

  @override
  String get attendanceInsideGeofence => 'Inside geofence';

  @override
  String get attendanceOutsideGeofence => 'Outside geofence';

  @override
  String get attendanceRecordedOffline => 'Recorded offline';

  @override
  String get attendanceSelfieUnavailable => 'Selfie unavailable';

  @override
  String get attendanceSelfieSemantic => 'Attendance selfie';

  @override
  String get attendanceErrNoConnection => 'No internet connection.';

  @override
  String get attendanceErrTimeout => 'The server took too long to respond.';

  @override
  String get attendanceErrServer =>
      'Something went wrong on the server. Please try again.';

  @override
  String get attendanceErrRateLimited =>
      'Too many requests. Please wait a moment.';

  @override
  String get attendanceErrSession =>
      'Your session expired. Sign in again to send this action.';

  @override
  String get authEnterEmail => 'Enter your email address';

  @override
  String get authEnterValidEmail => 'Enter a valid email address';

  @override
  String get authEnterServer => 'Enter the server address';

  @override
  String get authEnterValidServer => 'Enter a valid server address';

  @override
  String get authBadCredentials => 'The email or password is incorrect.';

  @override
  String get authAccountDisabled =>
      'Your account has been disabled. Please contact HR.';

  @override
  String get authNoEmployeeProfile =>
      'No employee profile is linked to this account.';

  @override
  String get authSessionExpired =>
      'Your session has expired. Please sign in again.';

  @override
  String authErrorRetryIn(String message, int seconds) {
    return '$message Try again in ${seconds}s.';
  }

  @override
  String get authWelcomeBack => 'Welcome back';

  @override
  String get authSignInSubtitle => 'Sign in to your account';

  @override
  String get authServerAddress => 'Server address';

  @override
  String get authServerHelper =>
      'The address of your PeopleOS server. Android emulator: http://10.0.2.2:8000';

  @override
  String get authEmailAddress => 'Email address';

  @override
  String get authPassword => 'Password';

  @override
  String get authPasswordHint => 'Enter your password';

  @override
  String get authPasswordRequired => 'Password is required';

  @override
  String get authShowPassword => 'Show password';

  @override
  String get authHidePassword => 'Hide password';

  @override
  String get authSignIn => 'Sign in';

  @override
  String get authForgotPassword => 'Forgot password?';

  @override
  String get authDemoAccount => 'Demo Account';

  @override
  String get authDemoNote =>
      'Demo mode: sample data on this device, no server needed.';

  @override
  String get authForgotTitle => 'Forgot your password?';

  @override
  String get authForgotBody =>
      'Password resets are handled by your HR administrator. Ask them to reset your password, then sign in with the new one.';

  @override
  String get authGotIt => 'Got it';

  @override
  String get authRememberMe => 'Remember me';

  @override
  String get authChange => 'Change';

  @override
  String get authTagline => 'Manage people. Simplify work.';

  @override
  String get authSplashSemantics => 'PeopleOS. Manage people. Simplify work.';

  @override
  String authVersion(String version) {
    return 'v$version';
  }

  @override
  String get profilePersonalInfo => 'Personal Information';

  @override
  String get profileEmployment => 'Employment';

  @override
  String get profileWorkInfo => 'Work Information';

  @override
  String get profileDocuments => 'Documents';

  @override
  String get profileNotifications => 'Notifications';

  @override
  String get profileSettings => 'Settings';

  @override
  String profileNewCount(int count) {
    return '$count new';
  }

  @override
  String get profileLogout => 'Logout';

  @override
  String get profileSignOutTitle => 'Sign out?';

  @override
  String get profileSignOutAction => 'Sign out';

  @override
  String profileSignOutPending(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count attendance actions',
      one: '1 attendance action',
    );
    return 'You have $_temp0 not synced yet. They stay safely on this device and sync after you sign in again.';
  }

  @override
  String get profileSignOutMessage =>
      'You will need to sign in again to use PeopleOS.';

  @override
  String get profileStatusActive => 'Active';

  @override
  String get profileStatusPending => 'Pending';

  @override
  String get profileStatusTerminated => 'Terminated';

  @override
  String get profileStatusInactive => 'Inactive';

  @override
  String get profileFullName => 'Full name';

  @override
  String get profileGender => 'Gender';

  @override
  String get profileDateOfBirth => 'Date of birth';

  @override
  String get profilePhone => 'Phone';

  @override
  String get profileEmail => 'Email';

  @override
  String get profileAddress => 'Address';

  @override
  String get profileEmployeeNumber => 'Employee number';

  @override
  String get profileDepartment => 'Department';

  @override
  String get profilePosition => 'Position';

  @override
  String get profileManager => 'Manager';

  @override
  String get profileJoinDate => 'Join date';

  @override
  String get profileEmploymentType => 'Employment type';

  @override
  String get profileStatus => 'Status';

  @override
  String get profileBranch => 'Branch';

  @override
  String get profileWorkLocation => 'Work location';

  @override
  String get profileShift => 'Shift';

  @override
  String get profileShiftHours => 'Shift hours';

  @override
  String get profileCompany => 'Company';

  @override
  String get profileTimezone => 'Timezone';

  @override
  String get profileGenderMale => 'Male';

  @override
  String get profileGenderFemale => 'Female';

  @override
  String get profileTypePermanent => 'Permanent';

  @override
  String get profileTypeContract => 'Contract';

  @override
  String get profileTypeProbation => 'Probation';

  @override
  String get profileTypeInternship => 'Internship';

  @override
  String get profileTypeFreelance => 'Freelance';

  @override
  String get profileTypePartTime => 'Part-time';

  @override
  String get settingsTitle => 'Settings';

  @override
  String get settingsAppearance => 'Appearance';

  @override
  String get settingsThemeLight => 'Light';

  @override
  String get settingsThemeDark => 'Dark';

  @override
  String get settingsThemeSystem => 'System';

  @override
  String get settingsGeneral => 'General';

  @override
  String get settingsSyncStatus => 'Sync status';

  @override
  String get settingsSyncSubtitle => 'Attendance saved on this device';

  @override
  String get settingsServerAddress => 'Server address';

  @override
  String get settingsNotSet => 'Not set';

  @override
  String get settingsAddress => 'Address';

  @override
  String get settingsServerHelper =>
      'Android emulator: http://10.0.2.2:8000  ·  iOS simulator: http://127.0.0.1:8000';

  @override
  String get settingsServerSaved => 'Server address saved';

  @override
  String get settingsDemoControls => 'Demo controls';

  @override
  String get settingsAbout => 'About';

  @override
  String get settingsAboutApp => 'App';

  @override
  String get settingsAboutVersion => 'Version';

  @override
  String get settingsAboutMode => 'Mode';

  @override
  String get settingsAboutCompany => 'Company';

  @override
  String get settingsAboutTimezone => 'Timezone';

  @override
  String get settingsAppName => 'PeopleOS Employee';

  @override
  String get settingsModeServer => 'Connected to server';

  @override
  String get settingsModeDemo => 'Demo (offline sample data)';

  @override
  String get settingsScenario => 'Scenario';

  @override
  String get settingsDemoScenario => 'Demo scenario';

  @override
  String get settingsScenarioNormal => 'Normal working day';

  @override
  String get settingsScenarioSelfie => 'Selfie required (strict policy)';

  @override
  String get settingsScenarioRestDay => 'Not a working day';

  @override
  String get settingsScenarioNoSchedule => 'No schedule assigned';

  @override
  String get settingsScenarioOnLeave => 'On approved leave today';

  @override
  String get settingsScenarioTerminated => 'Terminated employee (blocked)';

  @override
  String get settingsSimOutside => 'Simulate outside work area';

  @override
  String get settingsSimOutsideSub => 'Fake GPS ~330 m from the office';

  @override
  String get settingsSimOffline => 'Simulate offline';

  @override
  String get settingsSimOfflineSub =>
      'Try saving attendance without a connection';

  @override
  String get settingsResetDemo => 'Reset demo data';

  @override
  String get settingsResetDemoSub => 'Clears today\'s attendance and requests';

  @override
  String get settingsResetDemoTitle => 'Reset demo data?';

  @override
  String get settingsResetDemoMessage =>
      'Attendance and requests you created in the demo are removed.';

  @override
  String get settingsReset => 'Reset';

  @override
  String get settingsDemoDataReset => 'Demo data reset';

  @override
  String get settingsDemoModeNote =>
      'Demo mode runs entirely on this device with sample data.';

  @override
  String appComingSoonTitle(String feature) {
    return '$feature is coming soon';
  }

  @override
  String get appComingSoonGeneric => 'Coming soon';

  @override
  String get appComingSoonDocuments =>
      'Secure employee documents and expiry alerts arrive in an upcoming release.';

  @override
  String get appComingSoonMore =>
      'More employee services are on the roadmap. Attendance, requests and your profile are ready today.';

  @override
  String get appComingSoonDefault =>
      'This feature arrives in an upcoming release.';

  @override
  String get appFeatureDocuments => 'Documents';

  @override
  String get appFeatureMore => 'More';

  @override
  String get authServerHint => 'https://hr.yourcompany.com';

  @override
  String get authEmailHint => 'you@company.com';

  @override
  String get settingsServerHint => 'https://hr.yourcompany.com';

  @override
  String get checkTitleCheckIn => 'Check-in';

  @override
  String get checkTitleCheckOut => 'Check-out';

  @override
  String get checkConfirmLocation => 'Confirm your location';

  @override
  String get checkConfirmCheckOutTitle => 'Confirm check-out';

  @override
  String get checkSelfiePreviewTitle => 'Selfie preview';

  @override
  String get checkGettingLocation => 'Getting your location...';

  @override
  String get checkGps => 'GPS';

  @override
  String get checkWaiting => 'Waiting';

  @override
  String get checkLocationCaptured => 'Location captured';

  @override
  String get checkGeofence => 'Geofence';

  @override
  String get checkWithinArea => 'Within area';

  @override
  String get checkOutsideArea => 'Outside area';

  @override
  String get checkCheckedByServer => 'Checked by server';

  @override
  String get checkSelfie => 'Selfie';

  @override
  String get checkDone => 'Done';

  @override
  String get checkOfflineNote =>
      'You\'re offline. This check will be saved on your device and sent when you\'re back online.';

  @override
  String get checkBackToHome => 'Back to Home';

  @override
  String get checkConfirmCheckIn => 'Confirm Check In';

  @override
  String get checkConfirmCheckOut => 'Confirm Check Out';

  @override
  String get checkTakeSelfieToContinue => 'Take a selfie to continue.';

  @override
  String get checkRejectedLocation => 'Couldn\'t record your location';

  @override
  String get checkRejectedAttendance => 'Couldn\'t record your attendance';

  @override
  String get checkAreaInside => 'You are within work area';

  @override
  String get checkAreaOutside => 'You appear to be outside the work area';

  @override
  String get checkNoSite => 'No work site is configured for today.';

  @override
  String checkDistanceFromSite(String distance, String site) {
    return '$distance from $site';
  }

  @override
  String checkDistanceFromSiteLimit(String distance, String site, int limit) {
    return '$distance from $site (limit $limit m)';
  }

  @override
  String checkGpsAccuracy(int meters) {
    return 'GPS accuracy ±$meters m';
  }

  @override
  String get checkEstimatedOnDevice => 'Estimated on this device';

  @override
  String get checkServerDecides =>
      'The server makes the final decision. You can still try.';

  @override
  String get checkSelfieRequired => 'Selfie required';

  @override
  String get checkSelfieRequiredMsg =>
      'Your workplace asks for a photo with each check.';

  @override
  String get checkTake => 'Take';

  @override
  String get checkYourSelfie => 'Your selfie';

  @override
  String get checkSelfieReady => 'Selfie ready';

  @override
  String get checkSelfieReadyMsg => 'It is sent with this check only.';

  @override
  String get checkRetake => 'Retake';

  @override
  String get checkRemove => 'Remove';

  @override
  String get checkSelfiePreviewSemantic => 'Selfie preview';

  @override
  String get checkUsePhoto => 'Use photo';

  @override
  String get checkPermDeniedTitle => 'Location permission needed';

  @override
  String get checkPermDeniedMsg =>
      'PeopleOS uses your location only at the moment you check in or out, to confirm you are at work. It never tracks you in the background.';

  @override
  String get checkPermBlockedTitle => 'Location is blocked';

  @override
  String get checkPermBlockedMsg =>
      'Location access was turned off for PeopleOS. Open the app settings, allow Location, then come back.';

  @override
  String get checkOpenAppSettings => 'Open app settings';

  @override
  String get checkServiceOffTitle => 'Location services are off';

  @override
  String get checkServiceOffMsg =>
      'Turn on location (GPS) for this device so we can confirm you are at your work site.';

  @override
  String get checkOpenLocationSettings => 'Open location settings';

  @override
  String get checkUnavailableTitle => 'Couldn\'t get your location';

  @override
  String get checkUnavailableMsg =>
      'Move to an open area or near a window, then try again.';

  @override
  String checkResultCheckedInAt(String time) {
    return 'Checked in at $time';
  }

  @override
  String checkResultCheckedOutAt(String time) {
    return 'Checked out at $time';
  }

  @override
  String get checkResultSavedOnDevice => 'Saved on this device';

  @override
  String checkResultOfflineInBody(String time) {
    return 'Checked in at $time (pending sync). It will be sent automatically as soon as the connection is back. Nothing is lost.';
  }

  @override
  String checkResultOfflineOutBody(String time) {
    return 'Checked out at $time (pending sync). It will be sent automatically as soon as the connection is back. Nothing is lost.';
  }

  @override
  String get checkWorkSiteFallback => 'work site';

  @override
  String get checkSummaryRecorded => 'Your attendance has been recorded.';

  @override
  String checkSummaryLate(String duration) {
    return 'You are $duration late today. Have a good day!';
  }

  @override
  String get checkSummaryOnTime => 'You are on time. Have a great day!';

  @override
  String checkSummaryWorked(String duration) {
    return 'You worked $duration today. See you tomorrow!';
  }

  @override
  String get checkMapLocating => 'Locating you';

  @override
  String get checkMapGeneric => 'Location map';

  @override
  String checkMapRelative(String site) {
    return 'Map showing your position relative to $site';
  }

  @override
  String get commonCancel => 'Cancel';

  @override
  String get commonOk => 'OK';

  @override
  String get commonTryAgain => 'Try again';

  @override
  String get commonBack => 'Back';

  @override
  String get commonDone => 'Done';

  @override
  String get commonSave => 'Save';

  @override
  String get commonSubmit => 'Submit';

  @override
  String get commonClose => 'Close';

  @override
  String get commonRetry => 'Retry';

  @override
  String get commonSoon => 'Soon';

  @override
  String get commonYes => 'Yes';

  @override
  String get commonNo => 'No';

  @override
  String commonClear(String label) {
    return 'Clear $label';
  }

  @override
  String get commonSelect => 'Select';

  @override
  String get commonSelectItem => 'Select an item';

  @override
  String get commonLoading => 'Loading';

  @override
  String commonProfilePhotoOf(String name) {
    return 'Profile photo of $name';
  }

  @override
  String commonSyncStatus(String label) {
    return 'Sync status: $label';
  }

  @override
  String get commonOfflineTitle => 'You\'re offline.';

  @override
  String get commonOfflineSaved =>
      'Attendance is saved on this device and syncs automatically.';

  @override
  String commonOfflineWaiting(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count actions waiting to sync.',
      one: '1 action waiting to sync.',
    );
    return '$_temp0';
  }

  @override
  String get navHome => 'Home';

  @override
  String get navAttendance => 'Attendance';

  @override
  String get navRequests => 'Requests';

  @override
  String get navPayslip => 'Payslip';

  @override
  String get navProfile => 'Profile';

  @override
  String get badgeActive => 'Active';

  @override
  String get badgePending => 'Pending';

  @override
  String get badgeApproved => 'Approved';

  @override
  String get badgeRejected => 'Rejected';

  @override
  String get badgeExpired => 'Expired';

  @override
  String get badgeLate => 'Late';

  @override
  String get badgeOnTime => 'On time';

  @override
  String get badgeWorking => 'WORKING';

  @override
  String get badgeOffline => 'Offline';

  @override
  String get errOfflineTitle => 'You\'re offline';

  @override
  String get errTimeoutTitle => 'The server is slow to respond';

  @override
  String get errForbiddenTitle => 'Access denied';

  @override
  String get errNotFoundTitle => 'Not found';

  @override
  String get errServerTitle => 'Server problem';

  @override
  String get errGenericTitle => 'Something went wrong';

  @override
  String get errPleaseTryAgain => 'Please try again.';

  @override
  String get errNoConnection =>
      'No internet connection. Check your network and try again.';

  @override
  String get errTimeout => 'The server took too long to respond.';

  @override
  String get errServer =>
      'Something went wrong on the server. Please try again.';

  @override
  String get errForbidden => 'You don\'t have permission to do this.';

  @override
  String get errNotFound => 'We couldn\'t find what you were looking for.';

  @override
  String get errUnauthorized =>
      'Your session has expired. Please sign in again.';

  @override
  String get errRateLimited =>
      'Too many requests. Please try again in a moment.';

  @override
  String get errUnknown => 'Unexpected error. Please try again.';

  @override
  String errAttOutsideGeofenceDistance(int distance, int radius) {
    return 'You are $distance m from the work site. You must be within $radius m to record attendance.';
  }

  @override
  String get errAttOutsideGeofence => 'You are outside the allowed work area.';

  @override
  String get errAttGpsRequired => 'Location is required to record attendance.';

  @override
  String get errAttGpsAccuracyTooLow =>
      'GPS accuracy is too low. Move to an open area and try again.';

  @override
  String get errAttMockLocation =>
      'A mock location was detected. Turn it off and try again.';

  @override
  String get errAttLocationStale =>
      'The location fix is too old. Please try again.';

  @override
  String get errAttSelfieRequired => 'A selfie is required for this check.';

  @override
  String get errAttAlreadyCheckedIn => 'You are already checked in.';

  @override
  String get errAttAlreadyCheckedOut =>
      'You have already checked out for this day.';

  @override
  String get errAttNotCheckedIn => 'You need to check in before checking out.';

  @override
  String get errAttCheckOutTooLate =>
      'The check-out window for this shift has closed.';

  @override
  String get errAttOnLeave =>
      'You are on approved leave today, so check-in is not needed.';

  @override
  String get errAttNoSchedule => 'You have no schedule for today.';

  @override
  String get errAttNotWorkingDay => 'Today is not a working day for you.';

  @override
  String get errAttOfflineEventTooOld =>
      'This action was saved offline for too long to be accepted. Ask for an attendance correction instead.';

  @override
  String get errAttInvalidEventTime =>
      'The time recorded on this device was not accepted. Ask for an attendance correction instead.';

  @override
  String get errAttEmployeeTerminated =>
      'Your employment has ended. Attendance is disabled.';

  @override
  String get errAttEmployeeNotActive =>
      'Your employee profile is not active yet.';

  @override
  String get errAttAccountDisabled =>
      'Your account has been disabled. Contact HR.';

  @override
  String get errAttIdempotencyConflict =>
      'This action conflicts with an earlier one. Please refresh.';

  @override
  String get errFileMissing =>
      'The file is missing on the server. Please tell HR.';

  @override
  String get errFileUnavailable => 'This file is no longer available.';

  @override
  String get errFileForbidden => 'You are not allowed to open this file.';

  @override
  String get errFileOpenFailed => 'Could not open the file on this device.';

  @override
  String commonSharedFile(String name) {
    return 'Opened share sheet for $name';
  }

  @override
  String commonDownloadedFile(String name) {
    return 'Downloaded $name';
  }

  @override
  String get documentsTitle => 'Documents';

  @override
  String get documentsDetailTitle => 'Document';

  @override
  String get documentsSelectPrompt => 'Select a document';

  @override
  String get documentsEmptyTitle => 'No documents';

  @override
  String get documentsEmptyFiltered => 'Nothing matches these filters.';

  @override
  String get documentsEmptyDefault =>
      'Documents shared with you by HR appear here.';

  @override
  String get documentsFilterAll => 'All';

  @override
  String documentsFilterSemantics(String label) {
    return 'Filter: $label';
  }

  @override
  String get documentsStatusActive => 'Active';

  @override
  String get documentsStatusExpiring => 'Expiring';

  @override
  String get documentsStatusExpired => 'Expired';

  @override
  String get documentsStatusArchived => 'Archived';

  @override
  String documentsTypeCompany(String type) {
    return '$type · Company';
  }

  @override
  String documentsExpiredAgo(int n) {
    String _temp0 = intl.Intl.pluralLogic(
      n,
      locale: localeName,
      other: 'Expired $n days ago',
      one: 'Expired 1 day ago',
    );
    return '$_temp0';
  }

  @override
  String get documentsExpiresToday => 'Expires today';

  @override
  String get documentsExpiresTomorrow => 'Expires tomorrow';

  @override
  String documentsExpiresIn(int n) {
    return 'Expires in $n days';
  }

  @override
  String get documentsOwner => 'Owner';

  @override
  String get documentsOwnerCompany => 'Company document';

  @override
  String get documentsOwnerYou => 'You';

  @override
  String get documentsExpiryDate => 'Expiry date';

  @override
  String get documentsNoExpiry => 'No expiry';

  @override
  String get documentsFile => 'File';

  @override
  String documentsFileValue(String name, String size) {
    return '$name · $size';
  }

  @override
  String get documentsUploaded => 'Uploaded';

  @override
  String get documentsDownloadOpen => 'Download / open';

  @override
  String documentsFileSizeBytes(int n) {
    return '$n B';
  }

  @override
  String documentsFileSizeKb(String n) {
    return '$n KB';
  }

  @override
  String documentsFileSizeMb(String n) {
    return '$n MB';
  }

  @override
  String documentsSemantics(String title, String type, String status) {
    return '$title, $type, $status';
  }

  @override
  String documentsSemanticsExpiry(
    String title,
    String type,
    String status,
    String expiry,
  ) {
    return '$title, $type, $status, $expiry';
  }

  @override
  String get homeAttendance => 'Attendance';

  @override
  String get homeQuickLeave => 'Leave';

  @override
  String get homeQuickPayslip => 'Payslip';

  @override
  String get homeQuickAttendance => 'Attendance';

  @override
  String get homeQuickRequests => 'Requests';

  @override
  String get homeQuickDocuments => 'Documents';

  @override
  String get homeQuickMore => 'More';

  @override
  String get homeLoadingToday => 'Loading today';

  @override
  String get homeSavedWaitingSync => 'Saved on this device. Waiting to sync.';

  @override
  String homeFailedActions(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count attendance actions could not sync. Review',
      one: '1 attendance action could not sync. Review',
    );
    return '$_temp0';
  }

  @override
  String get homeStaleSchedule =>
      'Showing your last known schedule while offline.';

  @override
  String homeShiftStarts(String time) {
    return 'Shift starts $time';
  }

  @override
  String get homeNotCheckedIn => 'Not checked in';

  @override
  String get homeReadyWhenYouAre => 'Ready when you are.';

  @override
  String get homeCheckedInTitle => 'You\'re Checked In';

  @override
  String get homeBadgeWorking => 'WORKING';

  @override
  String get homeCheckedOutTitle => 'Checked out';

  @override
  String homeCheckedOutWorked(String inTime, String outTime, String duration) {
    return '$inTime - $outTime · $duration on the clock';
  }

  @override
  String get homeWaitingReview => 'Waiting for review';

  @override
  String get homeWaitingReviewMsg =>
      'Your attendance for today needs a manager review before it is final.';

  @override
  String get homeCorrectionPending => 'Correction pending';

  @override
  String get homeCorrectionPendingMsg =>
      'Your correction request is waiting for approval.';

  @override
  String get homeBadgePending => 'Pending';

  @override
  String get homeRejectedTitle => 'Attendance rejected';

  @override
  String get homeRejectedMsg =>
      'Your last correction was rejected. Contact your manager.';

  @override
  String get homeBadgeRejected => 'Rejected';

  @override
  String get homeNotWorkingDay => 'Not a working day';

  @override
  String get homeDayOffMsg =>
      'Enjoy your day off. Check-in is not needed today.';

  @override
  String get homeBadgeDayOff => 'Day off';

  @override
  String get homeOnLeaveTitle => 'On leave today';

  @override
  String get homeOnLeaveMsg =>
      'Enjoy your time off. Check-in is not needed while your leave is approved.';

  @override
  String get homeBadgeOnLeave => 'On leave';

  @override
  String get homeNoScheduleTitle => 'No schedule for today';

  @override
  String get homeNoScheduleMsg =>
      'No shift is assigned to you yet. Ask HR or your manager to assign one.';

  @override
  String get homeBadgeNoShift => 'No shift';

  @override
  String get homeBlockedTitle => 'Attendance unavailable';

  @override
  String get homeBlockedTerminatedMsg =>
      'Your employment has ended, so check-in is disabled. Your history stays available.';

  @override
  String get homeBlockedInactiveMsg =>
      'Your employee profile is not active yet. Contact HR.';

  @override
  String get homeBadgeTerminated => 'Terminated';

  @override
  String get homeBadgeInactive => 'Inactive';

  @override
  String homeSinceTime(String time) {
    return 'Since $time';
  }

  @override
  String homeTimeWorkedSemantic(String duration) {
    return 'Time worked $duration';
  }

  @override
  String get homeCheckIn => 'Check In';

  @override
  String get homeCheckOut => 'Check Out';

  @override
  String get homeViewRequests => 'View requests';

  @override
  String homeDepartment(String name) {
    return '$name Department';
  }

  @override
  String get homeNotifications => 'Notifications';

  @override
  String homeUnreadCount(int count) {
    return '$count unread';
  }

  @override
  String get homeRecentActivity => 'Recent activity';

  @override
  String get homeViewAll => 'View all';

  @override
  String get homeActivityUnavailable => 'Activity is unavailable right now.';

  @override
  String get homeNoAttendanceThisMonth =>
      'No attendance recorded yet this month.';

  @override
  String get homeNoUpcomingLeave => 'No upcoming leave';

  @override
  String get homeNextLeave => 'Next leave';

  @override
  String get homePlanTimeOff => 'Plan time off from Requests';

  @override
  String homePendingCount(int count) {
    return '$count pending';
  }

  @override
  String homeSummarySemantic(String title, String subtitle, int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count pending requests',
      one: '1 pending request',
    );
    return '$title. $subtitle. $_temp0.';
  }

  @override
  String get homeUpdates => 'Updates';

  @override
  String get homePayslipAvailable => 'Payslip available';

  @override
  String homeDocsNeedAttention(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count documents need attention',
      one: '1 document needs attention',
    );
    return '$_temp0';
  }

  @override
  String homeExpiringSoon(int count) {
    return '$count expiring soon';
  }

  @override
  String homeExpired(int count) {
    return '$count expired';
  }

  @override
  String get homeTodaysShift => 'Today\'s Shift';

  @override
  String get homeNotStarted => 'Not started';

  @override
  String get homeOnSchedule => 'On Schedule';

  @override
  String get homeNoShiftAssigned => 'No shift assigned';

  @override
  String get homeRestDay => 'Rest day';

  @override
  String get leaveErrOverlap =>
      'These dates overlap another leave request that is pending or approved.';

  @override
  String leaveErrInsufficientBalance(
    num available,
    String requestedDays,
    String availableDays,
  ) {
    String _temp0 = intl.Intl.pluralLogic(
      available,
      locale: localeName,
      other:
          'Not enough balance: you asked for $requestedDays but only $availableDays are available.',
      one:
          'Not enough balance: you asked for $requestedDays but only $availableDays is available.',
    );
    return '$_temp0';
  }

  @override
  String get leaveErrInsufficientBalanceGeneric =>
      'Not enough leave balance for these dates.';

  @override
  String get leaveErrNoticeTooShort =>
      'This leave type needs more advance notice. Choose a later start date.';

  @override
  String get leaveErrTooFarInPast =>
      'This leave starts too far in the past for this leave type.';

  @override
  String get leaveErrNoWorkingDays =>
      'The selected dates contain no working days (weekends and holidays are not counted).';

  @override
  String get leaveErrSpansYears =>
      'A request cannot cross into the next leave year. Split it into two requests.';

  @override
  String get leaveErrTooLong =>
      'This request is longer than the maximum allowed for this leave type. Shorten the dates or split it into separate requests.';

  @override
  String get leaveErrHalfDaySingleDate =>
      'A half day must start and end on the same date.';

  @override
  String get leaveErrHalfDayNotAllowed =>
      'This leave type does not allow half days.';

  @override
  String get leaveErrInvalidDateRange =>
      'The end date must be on or after the start date.';

  @override
  String get leaveErrTypeUnavailable =>
      'This leave type is no longer available.';

  @override
  String get leaveErrPolicyMissing =>
      'This leave type has no policy for you yet. Contact HR.';

  @override
  String get leaveErrAlreadyStarted =>
      'This leave has already started and can no longer be cancelled.';

  @override
  String get leaveErrRequestNotPending =>
      'This request was already decided or cancelled. Pull to refresh.';

  @override
  String get leaveErrEmployeeTerminated =>
      'Your employment has ended, so new leave cannot be requested.';

  @override
  String get leaveErrEmployeeNotActive =>
      'Your employee profile is not active yet. Contact HR.';

  @override
  String get leaveStatusDraft => 'Draft';

  @override
  String get leaveStatusPending => 'Pending';

  @override
  String get leaveStatusApproved => 'Approved';

  @override
  String get leaveStatusRejected => 'Rejected';

  @override
  String get leaveStatusCancelled => 'Cancelled';

  @override
  String get leaveHalfDayAm => 'Morning (AM)';

  @override
  String get leaveHalfDayPm => 'Afternoon (PM)';

  @override
  String get leaveHintUnlimited => 'Not limited by a yearly balance';

  @override
  String leaveHintPerYear(String days) {
    return '$days per year';
  }

  @override
  String leaveHintNotice(int n) {
    String _temp0 = intl.Intl.pluralLogic(
      n,
      locale: localeName,
      other: 'Request at least $n days ahead',
      one: 'Request at least 1 day ahead',
    );
    return '$_temp0';
  }

  @override
  String leaveHintMaxPast(int n) {
    String _temp0 = intl.Intl.pluralLogic(
      n,
      locale: localeName,
      other: 'Can start up to $n days in the past',
      one: 'Can start up to 1 day in the past',
    );
    return '$_temp0';
  }

  @override
  String leaveHintMaxConsecutive(int n) {
    return 'Max $n working days per request';
  }

  @override
  String get leaveHintHalfDay => 'Half days allowed';

  @override
  String get leaveHintReasonRequired => 'Reason required';

  @override
  String get leaveDurationCountedOnSubmit => 'Counted when submitted';

  @override
  String leaveDurationWithHalf(String days, String half) {
    return '$days · $half';
  }

  @override
  String get leaveBalanceDaysUsedYear => 'days used this year';

  @override
  String leaveBalanceOfAvailable(String total) {
    return 'of $total available';
  }

  @override
  String leaveBalancePending(String n) {
    return '$n pending';
  }

  @override
  String get leaveBalanceNoLimit => 'No yearly limit';

  @override
  String leaveBalanceUsedPending(String used, String pending) {
    return '$used used · $pending pending';
  }

  @override
  String leaveBalanceSemantics(
    String type,
    String headline,
    String caption,
    String footer,
  ) {
    return '$type: $headline $caption. $footer.';
  }

  @override
  String leaveCardSemantics(
    String type,
    String dates,
    String duration,
    String status,
  ) {
    return '$type, $dates, $duration, $status';
  }

  @override
  String leaveCardSubtitle(String type, String duration) {
    return '$type · $duration';
  }

  @override
  String get leavePreviewIntro =>
      'Choose a type and dates to see how many working days this uses.';

  @override
  String get leavePreviewCalculating => 'Calculating...';

  @override
  String get leavePreviewSummary => 'Summary';

  @override
  String leavePreviewDaysOfLeave(String days) {
    return '$days of leave';
  }

  @override
  String get leavePreviewNotCounted =>
      'Weekends and public holidays are not counted.';

  @override
  String leavePreviewMore(int n) {
    return '+$n more';
  }

  @override
  String get leavePreviewUnlimited => 'Not limited by a yearly balance.';

  @override
  String leavePreviewNotEnough(String available, String needed) {
    return 'Not enough balance: $available available, this needs $needed.';
  }

  @override
  String leavePreviewLeftAfter(String after, String available) {
    return '$after left after this request ($available available now).';
  }

  @override
  String get leaveRequestButton => 'Request leave';

  @override
  String get leaveEmptyTitle => 'No leave requests yet';

  @override
  String get leaveEmptyMessage =>
      'Plan time off: pick a type and dates, and see your balance before you submit.';

  @override
  String leaveEmptyFiltered(String status) {
    String _temp0 = intl.Intl.selectLogic(status, {
      'draft': 'No draft requests',
      'pending': 'No pending requests',
      'approved': 'No approved requests',
      'rejected': 'No rejected requests',
      'cancelled': 'No cancelled requests',
      'other': 'No requests',
    });
    return '$_temp0';
  }

  @override
  String get leaveFilterAll => 'All';

  @override
  String get leaveFilterPending => 'Pending';

  @override
  String get leaveFilterApproved => 'Approved';

  @override
  String get leaveFilterDrafts => 'Drafts';

  @override
  String get leaveFilterRejected => 'Rejected';

  @override
  String get leaveFilterCancelled => 'Cancelled';

  @override
  String leaveFilterSemantics(String label) {
    return 'Filter: $label';
  }

  @override
  String get leaveDetailTitle => 'Leave request';

  @override
  String get leaveConfirmDiscardTitle => 'Discard this draft?';

  @override
  String get leaveConfirmCancelTitle => 'Cancel this leave?';

  @override
  String get leaveConfirmCancelApprovedMessage =>
      'Your approved leave will be cancelled and the days returned to your balance.';

  @override
  String get leaveConfirmWithdrawMessage =>
      'The request will be withdrawn. You can submit a new one later.';

  @override
  String get leaveConfirmDiscard => 'Discard';

  @override
  String get leaveConfirmCancelLeave => 'Cancel leave';

  @override
  String get leaveKeepIt => 'Keep it';

  @override
  String get leaveDraftDiscarded => 'Draft discarded';

  @override
  String get leaveCancelledSnack => 'Leave cancelled';

  @override
  String get leaveUnpaid => 'Unpaid';

  @override
  String get leaveDetailDates => 'Dates';

  @override
  String get leaveDetailDuration => 'Duration';

  @override
  String get leaveDetailType => 'Type';

  @override
  String get leaveDetailWorkingDays => 'Working days';

  @override
  String get leaveDetailReason => 'Reason';

  @override
  String get leaveDetailRejectionReason => 'Rejection reason';

  @override
  String get leaveDetailStatus => 'Status';

  @override
  String get leaveSubmitRequest => 'Submit request';

  @override
  String get leaveSubmittedSnack => 'Leave request submitted';

  @override
  String get leaveEditDraft => 'Edit draft';

  @override
  String get leaveDiscardDraft => 'Discard draft';

  @override
  String get leaveCancelApproved => 'Cancel approved leave';

  @override
  String get leaveCancelRequest => 'Cancel request';

  @override
  String get leaveStepCreated => 'Created';

  @override
  String get leaveStepSubmitted => 'Submitted for approval';

  @override
  String get leaveStepDraft => 'Draft, not submitted yet';

  @override
  String get leaveStepWaiting => 'Waiting for your manager';

  @override
  String get leaveStepApproved => 'Approved';

  @override
  String leaveStepApprovedBy(String name) {
    return 'Approved by $name';
  }

  @override
  String get leaveStepRejected => 'Rejected';

  @override
  String leaveStepRejectedBy(String name) {
    return 'Rejected by $name';
  }

  @override
  String get leaveStepCancelled => 'Cancelled';

  @override
  String get leaveFormTitle => 'Request leave';

  @override
  String get leaveFormEditTitle => 'Edit leave draft';

  @override
  String get leaveFormType => 'Leave type';

  @override
  String get leaveFormTypeLoading => 'Loading leave types...';

  @override
  String get leaveFormTypeHint => 'Choose a type';

  @override
  String get leaveTypeNotAvailable => 'Not available for you yet';

  @override
  String get leaveTypeUnpaidNoLimit => 'Unpaid · no yearly limit';

  @override
  String leaveTypeAvailableOf(String available, String total) {
    return '$available available of $total';
  }

  @override
  String get leaveFormFirstDayHelp => 'First day of leave';

  @override
  String get leaveFormLastDayHelp => 'Last day of leave';

  @override
  String get leaveValChooseType => 'Choose a leave type.';

  @override
  String get leaveValChooseFirstDay => 'Choose the first day.';

  @override
  String get leaveValChooseLastDay => 'Choose the last day.';

  @override
  String leaveValReasonRequired(String type) {
    return 'A reason is required for $type.';
  }

  @override
  String get leaveDraftSaved => 'Draft saved';

  @override
  String get leaveSaveDraft => 'Save draft';

  @override
  String get leaveSaveAsDraft => 'Save as draft';

  @override
  String get leaveSubmit => 'Submit';

  @override
  String get leaveFormFrom => 'From';

  @override
  String get leaveFormTo => 'To';

  @override
  String get leaveFormFirstDay => 'First day';

  @override
  String get leaveFormLastDay => 'Last day';

  @override
  String get leaveFormLength => 'Length';

  @override
  String get leaveFormFullDay => 'Full day';

  @override
  String get leaveFormMorning => 'Morning';

  @override
  String get leaveFormAfternoon => 'Afternoon';

  @override
  String get leaveFormReasonRequired => 'Reason (required)';

  @override
  String get leaveFormReasonOptional => 'Reason (optional)';

  @override
  String get leaveFormReasonHint => 'e.g. Family event in Medan';

  @override
  String get notifTitle => 'Notifications';

  @override
  String get notifMarkAllRead => 'Mark all read';

  @override
  String get notifMarkAllFailed =>
      'Could not mark notifications as read. Nothing was changed.';

  @override
  String get notifEmptyTitle => 'You\'re all caught up';

  @override
  String get notifEmptyMessage =>
      'Updates about your leave, corrections and overtime appear here.';

  @override
  String notifSemantics(String title, String body, String age) {
    return '$title. $body. $age';
  }

  @override
  String notifSemanticsUnread(String title, String body, String age) {
    return 'Unread. $title. $body. $age';
  }

  @override
  String get notifTypeLeavePendingReview => 'Leave request to review';

  @override
  String get notifTypeLeaveApproved => 'Leave approved';

  @override
  String get notifTypeLeaveRejected => 'Leave rejected';

  @override
  String get notifTypeCorrectionApproved => 'Attendance correction approved';

  @override
  String get notifTypeCorrectionRejected => 'Attendance correction rejected';

  @override
  String get notifTypeOvertimeApproved => 'Overtime approved';

  @override
  String get notifTypeOvertimeRejected => 'Overtime rejected';

  @override
  String get notifTypePayslipAvailable => 'Payslip available';

  @override
  String get notifTypeDocumentExpiring => 'Document expiring';

  @override
  String get notifTypeDocumentExpired => 'Document expired';

  @override
  String get payslipTitle => 'Payslips';

  @override
  String get payslipDetailTitle => 'Payslip';

  @override
  String get payslipSelectPrompt => 'Select a payslip';

  @override
  String get payslipYearAll => 'All';

  @override
  String get payslipYearAllSemantics => 'All years';

  @override
  String payslipYearSemantics(String year) {
    return 'Year $year';
  }

  @override
  String get payslipEmptyTitle => 'No payslips yet';

  @override
  String get payslipEmptyMessage =>
      'Your payslips appear here once payroll for a period is released.';

  @override
  String get payslipStatusPaid => 'Paid';

  @override
  String get payslipStatusReleased => 'Released';

  @override
  String payslipCardSemanticsPaid(String period, String amount) {
    return '$period, net pay $amount, paid';
  }

  @override
  String payslipCardSemanticsReleased(String period, String amount) {
    return '$period, net pay $amount, released';
  }

  @override
  String get payslipCaption => 'PAYSLIP';

  @override
  String payslipPeriodRange(String start, String end) {
    return 'Period $start - $end';
  }

  @override
  String payslipPeriodRangePaid(String start, String end, String payDate) {
    return 'Period $start - $end · Paid $payDate';
  }

  @override
  String payslipInputsPresent(String present, String working) {
    return '$present of $working days present';
  }

  @override
  String payslipInputsOvertime(String duration) {
    return 'Overtime $duration';
  }

  @override
  String payslipInputsUnpaidLeave(String days) {
    return '$days unpaid leave days';
  }

  @override
  String get payslipEarnings => 'Earnings';

  @override
  String get payslipDeductions => 'Deductions';

  @override
  String get payslipGrossPay => 'Gross pay';

  @override
  String get payslipTotalDeductions => 'Total deductions';

  @override
  String get payslipNoDeductions => 'No deductions this period.';

  @override
  String get payslipNetPay => 'Net pay';

  @override
  String payslipNetPaySemantics(String amount) {
    return 'Net pay $amount';
  }

  @override
  String payslipAdjustment(String reason) {
    return 'Adjustment: $reason';
  }

  @override
  String get payslipDownloadPdf => 'Download PDF';

  @override
  String get requestsTitle => 'Requests';

  @override
  String get requestsTabLeave => 'Leave';

  @override
  String get requestsTabCorrections => 'Corrections';

  @override
  String get requestsTabOvertime => 'Overtime';

  @override
  String get requestsSelectPrompt => 'Select a request';

  @override
  String get requestsStatusPending => 'Pending';

  @override
  String get requestsStatusApproved => 'Approved';

  @override
  String get requestsStatusRejected => 'Rejected';

  @override
  String get requestsStatusCancelled => 'Cancelled';

  @override
  String get requestsNewCorrection => 'Request correction';

  @override
  String get requestsCorrectionsEmptyTitle => 'No correction requests';

  @override
  String get requestsCorrectionsEmptyMessage =>
      'Forgot to check out or in? Request a correction and your manager will review it.';

  @override
  String requestsCorrectionIn(String time) {
    return 'In $time';
  }

  @override
  String requestsCorrectionOut(String time) {
    return 'Out $time';
  }

  @override
  String get requestsNewOvertime => 'Request overtime';

  @override
  String get requestsOvertimeEmptyTitle => 'No overtime requests';

  @override
  String get requestsOvertimeEmptyMessage =>
      'Planning to work extra hours? Submit a request first.';

  @override
  String get requestsDetailWorkDate => 'Work date';

  @override
  String get requestsDetailCheckIn => 'Check in';

  @override
  String get requestsDetailCheckOut => 'Check out';

  @override
  String get requestsDetailSubmitted => 'Submitted';

  @override
  String get requestsDetailTime => 'Time';

  @override
  String get requestsDetailRequested => 'Requested';

  @override
  String get requestsDetailApproved => 'Approved';

  @override
  String get requestsCorrectionDetailTitle => 'Correction request';

  @override
  String get requestsOvertimeDetailTitle => 'Overtime request';

  @override
  String get requestsCorrectionCancelTitle => 'Cancel this request?';

  @override
  String get requestsOvertimeCancelTitle => 'Cancel this overtime request?';

  @override
  String get requestsCancelMessage =>
      'This cannot be undone. You can submit a new request later.';

  @override
  String get requestsCancelRequest => 'Cancel request';

  @override
  String get requestsKeepIt => 'Keep it';

  @override
  String get requestsCancelledSnack => 'Request cancelled';

  @override
  String get requestsReason => 'Reason';

  @override
  String get requestsReviewerNote => 'Reviewer note';

  @override
  String get requestsErrCorrectionAlreadyPending =>
      'There is already a pending correction for this date.';

  @override
  String get requestsErrCorrectionWindowExpired =>
      'Corrections can only be requested for the last 30 days.';

  @override
  String get requestsErrInvalidWorkDate => 'That work date is not valid.';

  @override
  String get requestsErrCheckInRequired =>
      'A check-in time is required for this request.';

  @override
  String get requestsErrOvertimeTooLong =>
      'That overtime is longer than the allowed limit.';

  @override
  String get requestsErrOvertimeOverlap =>
      'This overlaps another overtime request.';

  @override
  String get requestsErrNotPending =>
      'This request is no longer pending, so it cannot be changed.';

  @override
  String get requestsErrInvalidEventTime =>
      'The times you entered are not possible.';

  @override
  String get requestsCorrectionDateHelp => 'Work date (last 30 days)';

  @override
  String get requestsValChooseWorkDate => 'Choose the work date.';

  @override
  String get requestsValTimes =>
      'Add a check-in time, a check-out time, or both.';

  @override
  String get requestsValReasonWhatHappened =>
      'Explain what happened (at least 5 characters).';

  @override
  String get requestsCorrectionSubmitted => 'Correction request submitted';

  @override
  String get requestsSubmitRequest => 'Submit request';

  @override
  String get requestsCorrectionIntro =>
      'Forgot to check in or out? Tell us what the times should be. Your manager reviews the request before your record changes.';

  @override
  String get requestsChooseDate => 'Choose a date';

  @override
  String get requestsCheckInTime => 'Check-in time';

  @override
  String get requestsCheckOutTime => 'Check-out time';

  @override
  String get requestsOptional => 'Optional';

  @override
  String get requestsCorrectionReasonHint =>
      'e.g. I forgot to check out after the meeting';

  @override
  String get requestsOvertimeDateHelp => 'Overtime date';

  @override
  String get requestsValChooseDate => 'Choose the date.';

  @override
  String get requestsValChooseStart => 'Choose the start time.';

  @override
  String get requestsValChooseEnd => 'Choose the end time.';

  @override
  String get requestsValEndAfterStart =>
      'The end time must be after the start time.';

  @override
  String get requestsValReasonWhy => 'Explain why (at least 5 characters).';

  @override
  String get requestsOvertimeSubmitted => 'Overtime request submitted';

  @override
  String get requestsOvertimeIntro =>
      'Request approval before you work extra hours. Approved overtime is recorded for your manager and HR.';

  @override
  String get requestsDate => 'Date';

  @override
  String get requestsStartTime => 'Start time';

  @override
  String get requestsEndTime => 'End time';

  @override
  String get requestsStart => 'Start';

  @override
  String get requestsEnd => 'End';

  @override
  String get requestsOvertimeReasonHint => 'What will you work on?';

  @override
  String requestsTimeRange(String start, String end) {
    return '$start - $end';
  }

  @override
  String requestsOvertimeSubtitle(String start, String end, String duration) {
    return '$start - $end · $duration';
  }

  @override
  String get syncSyncing => 'Syncing...';

  @override
  String syncFailedCount(int count) {
    return '$count sync failed';
  }

  @override
  String syncPendingCount(int count) {
    return '$count pending sync';
  }

  @override
  String get syncAllSynced => 'All synced';

  @override
  String get syncTitle => 'Sync status';

  @override
  String get syncNow => 'Sync now';

  @override
  String get syncEverythingSynced => 'Everything is synced';

  @override
  String get syncEverythingSyncedMsg =>
      'Attendance you record while offline appears here until it reaches the server.';

  @override
  String get syncOnline => 'Online';

  @override
  String get syncOffline => 'Offline';

  @override
  String syncPendingFailedSummary(int pending, int failed) {
    return '$pending pending · $failed failed';
  }

  @override
  String get syncStateSaved => 'Saved';

  @override
  String get syncStateSynced => 'Synced';

  @override
  String syncAttempts(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count attempts',
      one: '1 attempt',
    );
    return '$_temp0';
  }

  @override
  String get syncDiscard => 'Discard';

  @override
  String get syncDiscardTitle => 'Discard this action?';

  @override
  String get syncDiscardMessage =>
      'It will not be sent to the server. Use a correction request if your attendance needs fixing.';
}
