import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/intl.dart' as intl;

import 'app_localizations_en.dart';
import 'app_localizations_id.dart';

// ignore_for_file: type=lint

/// Callers can lookup localized strings with an instance of AppLocalizations
/// returned by `AppLocalizations.of(context)`.
///
/// Applications need to include `AppLocalizations.delegate()` in their app's
/// `localizationDelegates` list, and the locales they support in the app's
/// `supportedLocales` list. For example:
///
/// ```dart
/// import 'generated/app_localizations.dart';
///
/// return MaterialApp(
///   localizationsDelegates: AppLocalizations.localizationsDelegates,
///   supportedLocales: AppLocalizations.supportedLocales,
///   home: MyApplicationHome(),
/// );
/// ```
///
/// ## Update pubspec.yaml
///
/// Please make sure to update your pubspec.yaml to include the following
/// packages:
///
/// ```yaml
/// dependencies:
///   # Internationalization support.
///   flutter_localizations:
///     sdk: flutter
///   intl: any # Use the pinned version from flutter_localizations
///
///   # Rest of dependencies
/// ```
///
/// ## iOS Applications
///
/// iOS applications define key application metadata, including supported
/// locales, in an Info.plist file that is built into the application bundle.
/// To configure the locales supported by your app, you’ll need to edit this
/// file.
///
/// First, open your project’s ios/Runner.xcworkspace Xcode workspace file.
/// Then, in the Project Navigator, open the Info.plist file under the Runner
/// project’s Runner folder.
///
/// Next, select the Information Property List item, select Add Item from the
/// Editor menu, then select Localizations from the pop-up menu.
///
/// Select and expand the newly-created Localizations item then, for each
/// locale your application supports, add a new item and select the locale
/// you wish to add from the pop-up menu in the Value field. This list should
/// be consistent with the languages listed in the AppLocalizations.supportedLocales
/// property.
abstract class AppLocalizations {
  AppLocalizations(String locale)
    : localeName = intl.Intl.canonicalizedLocale(locale.toString());

  final String localeName;

  static AppLocalizations of(BuildContext context) {
    return Localizations.of<AppLocalizations>(context, AppLocalizations)!;
  }

  static const LocalizationsDelegate<AppLocalizations> delegate =
      _AppLocalizationsDelegate();

  /// A list of this localizations delegate along with the default localizations
  /// delegates.
  ///
  /// Returns a list of localizations delegates containing this delegate along with
  /// GlobalMaterialLocalizations.delegate, GlobalCupertinoLocalizations.delegate,
  /// and GlobalWidgetsLocalizations.delegate.
  ///
  /// Additional delegates can be added by appending to this list in
  /// MaterialApp. This list does not have to be used at all if a custom list
  /// of delegates is preferred or required.
  static const List<LocalizationsDelegate<dynamic>> localizationsDelegates =
      <LocalizationsDelegate<dynamic>>[
        delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
      ];

  /// A list of this localizations delegate's supported locales.
  static const List<Locale> supportedLocales = <Locale>[
    Locale('en'),
    Locale('id'),
  ];

  /// No description provided for @appName.
  ///
  /// In en, this message translates to:
  /// **'PeopleOS'**
  String get appName;

  /// No description provided for @languageTitle.
  ///
  /// In en, this message translates to:
  /// **'Language'**
  String get languageTitle;

  /// No description provided for @languageEnglish.
  ///
  /// In en, this message translates to:
  /// **'English'**
  String get languageEnglish;

  /// No description provided for @languageIndonesian.
  ///
  /// In en, this message translates to:
  /// **'Bahasa Indonesia'**
  String get languageIndonesian;

  /// No description provided for @languageSystem.
  ///
  /// In en, this message translates to:
  /// **'Device default'**
  String get languageSystem;

  /// No description provided for @languageSavedLocalOnly.
  ///
  /// In en, this message translates to:
  /// **'Language changed on this device, but it could not be saved to your account.'**
  String get languageSavedLocalOnly;

  /// No description provided for @staleBanner.
  ///
  /// In en, this message translates to:
  /// **'Showing saved data from {age}. Reconnect and pull to refresh.'**
  String staleBanner(String age);

  /// No description provided for @ageJustNow.
  ///
  /// In en, this message translates to:
  /// **'just now'**
  String get ageJustNow;

  /// No description provided for @ageMinutes.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 minute ago} other{{count} minutes ago}}'**
  String ageMinutes(int count);

  /// No description provided for @ageHours.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 hour ago} other{{count} hours ago}}'**
  String ageHours(int count);

  /// No description provided for @ageDays.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 day ago} other{{count} days ago}}'**
  String ageDays(int count);

  /// No description provided for @durationHm.
  ///
  /// In en, this message translates to:
  /// **'{h}h {m}m'**
  String durationHm(int h, int m);

  /// No description provided for @durationH.
  ///
  /// In en, this message translates to:
  /// **'{h}h'**
  String durationH(int h);

  /// No description provided for @durationM.
  ///
  /// In en, this message translates to:
  /// **'{m}m'**
  String durationM(int m);

  /// No description provided for @greetingMorning.
  ///
  /// In en, this message translates to:
  /// **'Good morning'**
  String get greetingMorning;

  /// No description provided for @greetingAfternoon.
  ///
  /// In en, this message translates to:
  /// **'Good afternoon'**
  String get greetingAfternoon;

  /// No description provided for @greetingEvening.
  ///
  /// In en, this message translates to:
  /// **'Good evening'**
  String get greetingEvening;

  /// No description provided for @greetingNight.
  ///
  /// In en, this message translates to:
  /// **'Good night'**
  String get greetingNight;

  /// No description provided for @daysOne.
  ///
  /// In en, this message translates to:
  /// **'{n} day'**
  String daysOne(String n);

  /// No description provided for @daysMany.
  ///
  /// In en, this message translates to:
  /// **'{n} days'**
  String daysMany(String n);

  /// No description provided for @attendanceLate.
  ///
  /// In en, this message translates to:
  /// **'Late {duration}'**
  String attendanceLate(String duration);

  /// No description provided for @attendanceLateEstimate.
  ///
  /// In en, this message translates to:
  /// **'Late {duration} (estimate)'**
  String attendanceLateEstimate(String duration);

  /// No description provided for @attendanceOnTime.
  ///
  /// In en, this message translates to:
  /// **'On time'**
  String get attendanceOnTime;

  /// No description provided for @attendanceHistoryTitle.
  ///
  /// In en, this message translates to:
  /// **'Attendance History'**
  String get attendanceHistoryTitle;

  /// No description provided for @attendanceRequestCorrection.
  ///
  /// In en, this message translates to:
  /// **'Request correction'**
  String get attendanceRequestCorrection;

  /// No description provided for @attendancePreviousMonth.
  ///
  /// In en, this message translates to:
  /// **'Previous month'**
  String get attendancePreviousMonth;

  /// No description provided for @attendanceNextMonth.
  ///
  /// In en, this message translates to:
  /// **'Next month'**
  String get attendanceNextMonth;

  /// No description provided for @attendanceSelectDay.
  ///
  /// In en, this message translates to:
  /// **'Select a day'**
  String get attendanceSelectDay;

  /// No description provided for @attendanceEmptyTitle.
  ///
  /// In en, this message translates to:
  /// **'No attendance yet'**
  String get attendanceEmptyTitle;

  /// No description provided for @attendanceEmptyMessage.
  ///
  /// In en, this message translates to:
  /// **'Nothing recorded for {month}.'**
  String attendanceEmptyMessage(String month);

  /// No description provided for @attendanceStatPresent.
  ///
  /// In en, this message translates to:
  /// **'Present'**
  String get attendanceStatPresent;

  /// No description provided for @attendanceStatLate.
  ///
  /// In en, this message translates to:
  /// **'Late'**
  String get attendanceStatLate;

  /// No description provided for @attendanceStatHours.
  ///
  /// In en, this message translates to:
  /// **'Hours'**
  String get attendanceStatHours;

  /// No description provided for @attendanceStatSemantic.
  ///
  /// In en, this message translates to:
  /// **'{label}: {value}'**
  String attendanceStatSemantic(String label, String value);

  /// No description provided for @attendanceRowSemanticLate.
  ///
  /// In en, this message translates to:
  /// **'{date}, {times}, {minutes, plural, =1{late 1 minute} other{late {minutes} minutes}}'**
  String attendanceRowSemanticLate(String date, String times, int minutes);

  /// No description provided for @attendanceRowSemanticOnTime.
  ///
  /// In en, this message translates to:
  /// **'{date}, {times}, on time'**
  String attendanceRowSemanticOnTime(String date, String times);

  /// No description provided for @attendanceBadgeWorking.
  ///
  /// In en, this message translates to:
  /// **'Working'**
  String get attendanceBadgeWorking;

  /// No description provided for @attendanceBadgeCorrected.
  ///
  /// In en, this message translates to:
  /// **'Corrected'**
  String get attendanceBadgeCorrected;

  /// No description provided for @attendanceBadgeCorrection.
  ///
  /// In en, this message translates to:
  /// **'Correction'**
  String get attendanceBadgeCorrection;

  /// No description provided for @attendanceBadgeInReview.
  ///
  /// In en, this message translates to:
  /// **'In review'**
  String get attendanceBadgeInReview;

  /// No description provided for @attendanceBadgeRejected.
  ///
  /// In en, this message translates to:
  /// **'Rejected'**
  String get attendanceBadgeRejected;

  /// No description provided for @attendanceBadgePresent.
  ///
  /// In en, this message translates to:
  /// **'Present'**
  String get attendanceBadgePresent;

  /// No description provided for @attendanceSavedOnDevice.
  ///
  /// In en, this message translates to:
  /// **'Saved on this device'**
  String get attendanceSavedOnDevice;

  /// No description provided for @attendanceCouldNotSync.
  ///
  /// In en, this message translates to:
  /// **'Could not sync'**
  String get attendanceCouldNotSync;

  /// No description provided for @attendanceSyncFailed.
  ///
  /// In en, this message translates to:
  /// **'Sync failed'**
  String get attendanceSyncFailed;

  /// No description provided for @attendancePendingSync.
  ///
  /// In en, this message translates to:
  /// **'Pending sync'**
  String get attendancePendingSync;

  /// No description provided for @attendanceCheckTypeIn.
  ///
  /// In en, this message translates to:
  /// **'Check in'**
  String get attendanceCheckTypeIn;

  /// No description provided for @attendanceCheckTypeOut.
  ///
  /// In en, this message translates to:
  /// **'Check out'**
  String get attendanceCheckTypeOut;

  /// No description provided for @attendanceLeftEarly.
  ///
  /// In en, this message translates to:
  /// **'Left {duration} early'**
  String attendanceLeftEarly(String duration);

  /// No description provided for @attendanceKvCheckIn.
  ///
  /// In en, this message translates to:
  /// **'Check in'**
  String get attendanceKvCheckIn;

  /// No description provided for @attendanceKvCheckOut.
  ///
  /// In en, this message translates to:
  /// **'Check out'**
  String get attendanceKvCheckOut;

  /// No description provided for @attendanceKvWorked.
  ///
  /// In en, this message translates to:
  /// **'Worked'**
  String get attendanceKvWorked;

  /// No description provided for @attendanceKvShift.
  ///
  /// In en, this message translates to:
  /// **'Shift'**
  String get attendanceKvShift;

  /// No description provided for @attendanceKvLocation.
  ///
  /// In en, this message translates to:
  /// **'Location'**
  String get attendanceKvLocation;

  /// No description provided for @attendanceCheckInDetails.
  ///
  /// In en, this message translates to:
  /// **'Check-in details'**
  String get attendanceCheckInDetails;

  /// No description provided for @attendanceCheckOutDetails.
  ///
  /// In en, this message translates to:
  /// **'Check-out details'**
  String get attendanceCheckOutDetails;

  /// No description provided for @attendanceReviewNote.
  ///
  /// In en, this message translates to:
  /// **'Review note'**
  String get attendanceReviewNote;

  /// No description provided for @attendanceInsideGeofence.
  ///
  /// In en, this message translates to:
  /// **'Inside geofence'**
  String get attendanceInsideGeofence;

  /// No description provided for @attendanceOutsideGeofence.
  ///
  /// In en, this message translates to:
  /// **'Outside geofence'**
  String get attendanceOutsideGeofence;

  /// No description provided for @attendanceRecordedOffline.
  ///
  /// In en, this message translates to:
  /// **'Recorded offline'**
  String get attendanceRecordedOffline;

  /// No description provided for @attendanceSelfieUnavailable.
  ///
  /// In en, this message translates to:
  /// **'Selfie unavailable'**
  String get attendanceSelfieUnavailable;

  /// No description provided for @attendanceSelfieSemantic.
  ///
  /// In en, this message translates to:
  /// **'Attendance selfie'**
  String get attendanceSelfieSemantic;

  /// No description provided for @attendanceErrNoConnection.
  ///
  /// In en, this message translates to:
  /// **'No internet connection.'**
  String get attendanceErrNoConnection;

  /// No description provided for @attendanceErrTimeout.
  ///
  /// In en, this message translates to:
  /// **'The server took too long to respond.'**
  String get attendanceErrTimeout;

  /// No description provided for @attendanceErrServer.
  ///
  /// In en, this message translates to:
  /// **'Something went wrong on the server. Please try again.'**
  String get attendanceErrServer;

  /// No description provided for @attendanceErrRateLimited.
  ///
  /// In en, this message translates to:
  /// **'Too many requests. Please wait a moment.'**
  String get attendanceErrRateLimited;

  /// No description provided for @attendanceErrSession.
  ///
  /// In en, this message translates to:
  /// **'Your session expired. Sign in again to send this action.'**
  String get attendanceErrSession;

  /// No description provided for @authEnterEmail.
  ///
  /// In en, this message translates to:
  /// **'Enter your email address'**
  String get authEnterEmail;

  /// No description provided for @authEnterValidEmail.
  ///
  /// In en, this message translates to:
  /// **'Enter a valid email address'**
  String get authEnterValidEmail;

  /// No description provided for @authEnterServer.
  ///
  /// In en, this message translates to:
  /// **'Enter the server address'**
  String get authEnterServer;

  /// No description provided for @authEnterValidServer.
  ///
  /// In en, this message translates to:
  /// **'Enter a valid server address'**
  String get authEnterValidServer;

  /// No description provided for @authBadCredentials.
  ///
  /// In en, this message translates to:
  /// **'The email or password is incorrect.'**
  String get authBadCredentials;

  /// No description provided for @authAccountDisabled.
  ///
  /// In en, this message translates to:
  /// **'Your account has been disabled. Please contact HR.'**
  String get authAccountDisabled;

  /// No description provided for @authNoEmployeeProfile.
  ///
  /// In en, this message translates to:
  /// **'No employee profile is linked to this account.'**
  String get authNoEmployeeProfile;

  /// No description provided for @authSessionExpired.
  ///
  /// In en, this message translates to:
  /// **'Your session has expired. Please sign in again.'**
  String get authSessionExpired;

  /// No description provided for @authErrorRetryIn.
  ///
  /// In en, this message translates to:
  /// **'{message} Try again in {seconds}s.'**
  String authErrorRetryIn(String message, int seconds);

  /// No description provided for @authWelcomeBack.
  ///
  /// In en, this message translates to:
  /// **'Welcome back'**
  String get authWelcomeBack;

  /// No description provided for @authSignInSubtitle.
  ///
  /// In en, this message translates to:
  /// **'Sign in to your account'**
  String get authSignInSubtitle;

  /// No description provided for @authServerAddress.
  ///
  /// In en, this message translates to:
  /// **'Server address'**
  String get authServerAddress;

  /// No description provided for @authServerHelper.
  ///
  /// In en, this message translates to:
  /// **'The address of your PeopleOS server. Android emulator: http://10.0.2.2:8000'**
  String get authServerHelper;

  /// No description provided for @authEmailAddress.
  ///
  /// In en, this message translates to:
  /// **'Email address'**
  String get authEmailAddress;

  /// No description provided for @authPassword.
  ///
  /// In en, this message translates to:
  /// **'Password'**
  String get authPassword;

  /// No description provided for @authPasswordHint.
  ///
  /// In en, this message translates to:
  /// **'Enter your password'**
  String get authPasswordHint;

  /// No description provided for @authPasswordRequired.
  ///
  /// In en, this message translates to:
  /// **'Password is required'**
  String get authPasswordRequired;

  /// No description provided for @authShowPassword.
  ///
  /// In en, this message translates to:
  /// **'Show password'**
  String get authShowPassword;

  /// No description provided for @authHidePassword.
  ///
  /// In en, this message translates to:
  /// **'Hide password'**
  String get authHidePassword;

  /// No description provided for @authSignIn.
  ///
  /// In en, this message translates to:
  /// **'Sign in'**
  String get authSignIn;

  /// No description provided for @authForgotPassword.
  ///
  /// In en, this message translates to:
  /// **'Forgot password?'**
  String get authForgotPassword;

  /// No description provided for @authDemoAccount.
  ///
  /// In en, this message translates to:
  /// **'Demo Account'**
  String get authDemoAccount;

  /// No description provided for @authDemoNote.
  ///
  /// In en, this message translates to:
  /// **'Demo mode: sample data on this device, no server needed.'**
  String get authDemoNote;

  /// No description provided for @authForgotTitle.
  ///
  /// In en, this message translates to:
  /// **'Forgot your password?'**
  String get authForgotTitle;

  /// No description provided for @authForgotBody.
  ///
  /// In en, this message translates to:
  /// **'Password resets are handled by your HR administrator. Ask them to reset your password, then sign in with the new one.'**
  String get authForgotBody;

  /// No description provided for @authGotIt.
  ///
  /// In en, this message translates to:
  /// **'Got it'**
  String get authGotIt;

  /// No description provided for @authRememberMe.
  ///
  /// In en, this message translates to:
  /// **'Remember me'**
  String get authRememberMe;

  /// No description provided for @authChange.
  ///
  /// In en, this message translates to:
  /// **'Change'**
  String get authChange;

  /// No description provided for @authTagline.
  ///
  /// In en, this message translates to:
  /// **'Manage people. Simplify work.'**
  String get authTagline;

  /// No description provided for @authSplashSemantics.
  ///
  /// In en, this message translates to:
  /// **'PeopleOS. Manage people. Simplify work.'**
  String get authSplashSemantics;

  /// No description provided for @authVersion.
  ///
  /// In en, this message translates to:
  /// **'v{version}'**
  String authVersion(String version);

  /// No description provided for @profilePersonalInfo.
  ///
  /// In en, this message translates to:
  /// **'Personal Information'**
  String get profilePersonalInfo;

  /// No description provided for @profileEmployment.
  ///
  /// In en, this message translates to:
  /// **'Employment'**
  String get profileEmployment;

  /// No description provided for @profileWorkInfo.
  ///
  /// In en, this message translates to:
  /// **'Work Information'**
  String get profileWorkInfo;

  /// No description provided for @profileDocuments.
  ///
  /// In en, this message translates to:
  /// **'Documents'**
  String get profileDocuments;

  /// No description provided for @profileNotifications.
  ///
  /// In en, this message translates to:
  /// **'Notifications'**
  String get profileNotifications;

  /// No description provided for @profileSettings.
  ///
  /// In en, this message translates to:
  /// **'Settings'**
  String get profileSettings;

  /// No description provided for @profileNewCount.
  ///
  /// In en, this message translates to:
  /// **'{count} new'**
  String profileNewCount(int count);

  /// No description provided for @profileLogout.
  ///
  /// In en, this message translates to:
  /// **'Logout'**
  String get profileLogout;

  /// No description provided for @profileSignOutTitle.
  ///
  /// In en, this message translates to:
  /// **'Sign out?'**
  String get profileSignOutTitle;

  /// No description provided for @profileSignOutAction.
  ///
  /// In en, this message translates to:
  /// **'Sign out'**
  String get profileSignOutAction;

  /// No description provided for @profileSignOutPending.
  ///
  /// In en, this message translates to:
  /// **'You have {count, plural, =1{1 attendance action} other{{count} attendance actions}} not synced yet. They stay safely on this device and sync after you sign in again.'**
  String profileSignOutPending(int count);

  /// No description provided for @profileSignOutMessage.
  ///
  /// In en, this message translates to:
  /// **'You will need to sign in again to use PeopleOS.'**
  String get profileSignOutMessage;

  /// No description provided for @profileStatusActive.
  ///
  /// In en, this message translates to:
  /// **'Active'**
  String get profileStatusActive;

  /// No description provided for @profileStatusPending.
  ///
  /// In en, this message translates to:
  /// **'Pending'**
  String get profileStatusPending;

  /// No description provided for @profileStatusTerminated.
  ///
  /// In en, this message translates to:
  /// **'Terminated'**
  String get profileStatusTerminated;

  /// No description provided for @profileStatusInactive.
  ///
  /// In en, this message translates to:
  /// **'Inactive'**
  String get profileStatusInactive;

  /// No description provided for @profileFullName.
  ///
  /// In en, this message translates to:
  /// **'Full name'**
  String get profileFullName;

  /// No description provided for @profileGender.
  ///
  /// In en, this message translates to:
  /// **'Gender'**
  String get profileGender;

  /// No description provided for @profileDateOfBirth.
  ///
  /// In en, this message translates to:
  /// **'Date of birth'**
  String get profileDateOfBirth;

  /// No description provided for @profilePhone.
  ///
  /// In en, this message translates to:
  /// **'Phone'**
  String get profilePhone;

  /// No description provided for @profileEmail.
  ///
  /// In en, this message translates to:
  /// **'Email'**
  String get profileEmail;

  /// No description provided for @profileAddress.
  ///
  /// In en, this message translates to:
  /// **'Address'**
  String get profileAddress;

  /// No description provided for @profileEmployeeNumber.
  ///
  /// In en, this message translates to:
  /// **'Employee number'**
  String get profileEmployeeNumber;

  /// No description provided for @profileDepartment.
  ///
  /// In en, this message translates to:
  /// **'Department'**
  String get profileDepartment;

  /// No description provided for @profilePosition.
  ///
  /// In en, this message translates to:
  /// **'Position'**
  String get profilePosition;

  /// No description provided for @profileManager.
  ///
  /// In en, this message translates to:
  /// **'Manager'**
  String get profileManager;

  /// No description provided for @profileJoinDate.
  ///
  /// In en, this message translates to:
  /// **'Join date'**
  String get profileJoinDate;

  /// No description provided for @profileEmploymentType.
  ///
  /// In en, this message translates to:
  /// **'Employment type'**
  String get profileEmploymentType;

  /// No description provided for @profileStatus.
  ///
  /// In en, this message translates to:
  /// **'Status'**
  String get profileStatus;

  /// No description provided for @profileBranch.
  ///
  /// In en, this message translates to:
  /// **'Branch'**
  String get profileBranch;

  /// No description provided for @profileWorkLocation.
  ///
  /// In en, this message translates to:
  /// **'Work location'**
  String get profileWorkLocation;

  /// No description provided for @profileShift.
  ///
  /// In en, this message translates to:
  /// **'Shift'**
  String get profileShift;

  /// No description provided for @profileShiftHours.
  ///
  /// In en, this message translates to:
  /// **'Shift hours'**
  String get profileShiftHours;

  /// No description provided for @profileCompany.
  ///
  /// In en, this message translates to:
  /// **'Company'**
  String get profileCompany;

  /// No description provided for @profileTimezone.
  ///
  /// In en, this message translates to:
  /// **'Timezone'**
  String get profileTimezone;

  /// No description provided for @profileGenderMale.
  ///
  /// In en, this message translates to:
  /// **'Male'**
  String get profileGenderMale;

  /// No description provided for @profileGenderFemale.
  ///
  /// In en, this message translates to:
  /// **'Female'**
  String get profileGenderFemale;

  /// No description provided for @profileTypePermanent.
  ///
  /// In en, this message translates to:
  /// **'Permanent'**
  String get profileTypePermanent;

  /// No description provided for @profileTypeContract.
  ///
  /// In en, this message translates to:
  /// **'Contract'**
  String get profileTypeContract;

  /// No description provided for @profileTypeProbation.
  ///
  /// In en, this message translates to:
  /// **'Probation'**
  String get profileTypeProbation;

  /// No description provided for @profileTypeInternship.
  ///
  /// In en, this message translates to:
  /// **'Internship'**
  String get profileTypeInternship;

  /// No description provided for @profileTypeFreelance.
  ///
  /// In en, this message translates to:
  /// **'Freelance'**
  String get profileTypeFreelance;

  /// No description provided for @profileTypePartTime.
  ///
  /// In en, this message translates to:
  /// **'Part-time'**
  String get profileTypePartTime;

  /// No description provided for @settingsTitle.
  ///
  /// In en, this message translates to:
  /// **'Settings'**
  String get settingsTitle;

  /// No description provided for @settingsAppearance.
  ///
  /// In en, this message translates to:
  /// **'Appearance'**
  String get settingsAppearance;

  /// No description provided for @settingsThemeLight.
  ///
  /// In en, this message translates to:
  /// **'Light'**
  String get settingsThemeLight;

  /// No description provided for @settingsThemeDark.
  ///
  /// In en, this message translates to:
  /// **'Dark'**
  String get settingsThemeDark;

  /// No description provided for @settingsThemeSystem.
  ///
  /// In en, this message translates to:
  /// **'System'**
  String get settingsThemeSystem;

  /// No description provided for @settingsGeneral.
  ///
  /// In en, this message translates to:
  /// **'General'**
  String get settingsGeneral;

  /// No description provided for @settingsSyncStatus.
  ///
  /// In en, this message translates to:
  /// **'Sync status'**
  String get settingsSyncStatus;

  /// No description provided for @settingsSyncSubtitle.
  ///
  /// In en, this message translates to:
  /// **'Attendance saved on this device'**
  String get settingsSyncSubtitle;

  /// No description provided for @settingsServerAddress.
  ///
  /// In en, this message translates to:
  /// **'Server address'**
  String get settingsServerAddress;

  /// No description provided for @settingsNotSet.
  ///
  /// In en, this message translates to:
  /// **'Not set'**
  String get settingsNotSet;

  /// No description provided for @settingsAddress.
  ///
  /// In en, this message translates to:
  /// **'Address'**
  String get settingsAddress;

  /// No description provided for @settingsServerHelper.
  ///
  /// In en, this message translates to:
  /// **'Android emulator: http://10.0.2.2:8000  ·  iOS simulator: http://127.0.0.1:8000'**
  String get settingsServerHelper;

  /// No description provided for @settingsServerSaved.
  ///
  /// In en, this message translates to:
  /// **'Server address saved'**
  String get settingsServerSaved;

  /// No description provided for @settingsDemoControls.
  ///
  /// In en, this message translates to:
  /// **'Demo controls'**
  String get settingsDemoControls;

  /// No description provided for @settingsAbout.
  ///
  /// In en, this message translates to:
  /// **'About'**
  String get settingsAbout;

  /// No description provided for @settingsAboutApp.
  ///
  /// In en, this message translates to:
  /// **'App'**
  String get settingsAboutApp;

  /// No description provided for @settingsAboutVersion.
  ///
  /// In en, this message translates to:
  /// **'Version'**
  String get settingsAboutVersion;

  /// No description provided for @settingsAboutMode.
  ///
  /// In en, this message translates to:
  /// **'Mode'**
  String get settingsAboutMode;

  /// No description provided for @settingsAboutCompany.
  ///
  /// In en, this message translates to:
  /// **'Company'**
  String get settingsAboutCompany;

  /// No description provided for @settingsAboutTimezone.
  ///
  /// In en, this message translates to:
  /// **'Timezone'**
  String get settingsAboutTimezone;

  /// No description provided for @settingsAppName.
  ///
  /// In en, this message translates to:
  /// **'PeopleOS Employee'**
  String get settingsAppName;

  /// No description provided for @settingsModeServer.
  ///
  /// In en, this message translates to:
  /// **'Connected to server'**
  String get settingsModeServer;

  /// No description provided for @settingsModeDemo.
  ///
  /// In en, this message translates to:
  /// **'Demo (offline sample data)'**
  String get settingsModeDemo;

  /// No description provided for @settingsScenario.
  ///
  /// In en, this message translates to:
  /// **'Scenario'**
  String get settingsScenario;

  /// No description provided for @settingsDemoScenario.
  ///
  /// In en, this message translates to:
  /// **'Demo scenario'**
  String get settingsDemoScenario;

  /// No description provided for @settingsScenarioNormal.
  ///
  /// In en, this message translates to:
  /// **'Normal working day'**
  String get settingsScenarioNormal;

  /// No description provided for @settingsScenarioSelfie.
  ///
  /// In en, this message translates to:
  /// **'Selfie required (strict policy)'**
  String get settingsScenarioSelfie;

  /// No description provided for @settingsScenarioRestDay.
  ///
  /// In en, this message translates to:
  /// **'Not a working day'**
  String get settingsScenarioRestDay;

  /// No description provided for @settingsScenarioNoSchedule.
  ///
  /// In en, this message translates to:
  /// **'No schedule assigned'**
  String get settingsScenarioNoSchedule;

  /// No description provided for @settingsScenarioOnLeave.
  ///
  /// In en, this message translates to:
  /// **'On approved leave today'**
  String get settingsScenarioOnLeave;

  /// No description provided for @settingsScenarioTerminated.
  ///
  /// In en, this message translates to:
  /// **'Terminated employee (blocked)'**
  String get settingsScenarioTerminated;

  /// No description provided for @settingsSimOutside.
  ///
  /// In en, this message translates to:
  /// **'Simulate outside work area'**
  String get settingsSimOutside;

  /// No description provided for @settingsSimOutsideSub.
  ///
  /// In en, this message translates to:
  /// **'Fake GPS ~330 m from the office'**
  String get settingsSimOutsideSub;

  /// No description provided for @settingsSimOffline.
  ///
  /// In en, this message translates to:
  /// **'Simulate offline'**
  String get settingsSimOffline;

  /// No description provided for @settingsSimOfflineSub.
  ///
  /// In en, this message translates to:
  /// **'Try saving attendance without a connection'**
  String get settingsSimOfflineSub;

  /// No description provided for @settingsResetDemo.
  ///
  /// In en, this message translates to:
  /// **'Reset demo data'**
  String get settingsResetDemo;

  /// No description provided for @settingsResetDemoSub.
  ///
  /// In en, this message translates to:
  /// **'Clears today\'\'s attendance and requests'**
  String get settingsResetDemoSub;

  /// No description provided for @settingsResetDemoTitle.
  ///
  /// In en, this message translates to:
  /// **'Reset demo data?'**
  String get settingsResetDemoTitle;

  /// No description provided for @settingsResetDemoMessage.
  ///
  /// In en, this message translates to:
  /// **'Attendance and requests you created in the demo are removed.'**
  String get settingsResetDemoMessage;

  /// No description provided for @settingsReset.
  ///
  /// In en, this message translates to:
  /// **'Reset'**
  String get settingsReset;

  /// No description provided for @settingsDemoDataReset.
  ///
  /// In en, this message translates to:
  /// **'Demo data reset'**
  String get settingsDemoDataReset;

  /// No description provided for @settingsDemoModeNote.
  ///
  /// In en, this message translates to:
  /// **'Demo mode runs entirely on this device with sample data.'**
  String get settingsDemoModeNote;

  /// No description provided for @appComingSoonTitle.
  ///
  /// In en, this message translates to:
  /// **'{feature} is coming soon'**
  String appComingSoonTitle(String feature);

  /// No description provided for @appComingSoonGeneric.
  ///
  /// In en, this message translates to:
  /// **'Coming soon'**
  String get appComingSoonGeneric;

  /// No description provided for @appComingSoonDocuments.
  ///
  /// In en, this message translates to:
  /// **'Secure employee documents and expiry alerts arrive in an upcoming release.'**
  String get appComingSoonDocuments;

  /// No description provided for @appComingSoonMore.
  ///
  /// In en, this message translates to:
  /// **'More employee services are on the roadmap. Attendance, requests and your profile are ready today.'**
  String get appComingSoonMore;

  /// No description provided for @appComingSoonDefault.
  ///
  /// In en, this message translates to:
  /// **'This feature arrives in an upcoming release.'**
  String get appComingSoonDefault;

  /// No description provided for @appFeatureDocuments.
  ///
  /// In en, this message translates to:
  /// **'Documents'**
  String get appFeatureDocuments;

  /// No description provided for @appFeatureMore.
  ///
  /// In en, this message translates to:
  /// **'More'**
  String get appFeatureMore;

  /// No description provided for @authServerHint.
  ///
  /// In en, this message translates to:
  /// **'https://hr.yourcompany.com'**
  String get authServerHint;

  /// No description provided for @authEmailHint.
  ///
  /// In en, this message translates to:
  /// **'you@company.com'**
  String get authEmailHint;

  /// No description provided for @settingsServerHint.
  ///
  /// In en, this message translates to:
  /// **'https://hr.yourcompany.com'**
  String get settingsServerHint;

  /// No description provided for @checkTitleCheckIn.
  ///
  /// In en, this message translates to:
  /// **'Check-in'**
  String get checkTitleCheckIn;

  /// No description provided for @checkTitleCheckOut.
  ///
  /// In en, this message translates to:
  /// **'Check-out'**
  String get checkTitleCheckOut;

  /// No description provided for @checkConfirmLocation.
  ///
  /// In en, this message translates to:
  /// **'Confirm your location'**
  String get checkConfirmLocation;

  /// No description provided for @checkConfirmCheckOutTitle.
  ///
  /// In en, this message translates to:
  /// **'Confirm check-out'**
  String get checkConfirmCheckOutTitle;

  /// No description provided for @checkSelfiePreviewTitle.
  ///
  /// In en, this message translates to:
  /// **'Selfie preview'**
  String get checkSelfiePreviewTitle;

  /// No description provided for @checkGettingLocation.
  ///
  /// In en, this message translates to:
  /// **'Getting your location...'**
  String get checkGettingLocation;

  /// No description provided for @checkGps.
  ///
  /// In en, this message translates to:
  /// **'GPS'**
  String get checkGps;

  /// No description provided for @checkWaiting.
  ///
  /// In en, this message translates to:
  /// **'Waiting'**
  String get checkWaiting;

  /// No description provided for @checkLocationCaptured.
  ///
  /// In en, this message translates to:
  /// **'Location captured'**
  String get checkLocationCaptured;

  /// No description provided for @checkGeofence.
  ///
  /// In en, this message translates to:
  /// **'Geofence'**
  String get checkGeofence;

  /// No description provided for @checkWithinArea.
  ///
  /// In en, this message translates to:
  /// **'Within area'**
  String get checkWithinArea;

  /// No description provided for @checkOutsideArea.
  ///
  /// In en, this message translates to:
  /// **'Outside area'**
  String get checkOutsideArea;

  /// No description provided for @checkCheckedByServer.
  ///
  /// In en, this message translates to:
  /// **'Checked by server'**
  String get checkCheckedByServer;

  /// No description provided for @checkSelfie.
  ///
  /// In en, this message translates to:
  /// **'Selfie'**
  String get checkSelfie;

  /// No description provided for @checkDone.
  ///
  /// In en, this message translates to:
  /// **'Done'**
  String get checkDone;

  /// No description provided for @checkOfflineNote.
  ///
  /// In en, this message translates to:
  /// **'You\'\'re offline. This check will be saved on your device and sent when you\'\'re back online.'**
  String get checkOfflineNote;

  /// No description provided for @checkBackToHome.
  ///
  /// In en, this message translates to:
  /// **'Back to Home'**
  String get checkBackToHome;

  /// No description provided for @checkConfirmCheckIn.
  ///
  /// In en, this message translates to:
  /// **'Confirm Check In'**
  String get checkConfirmCheckIn;

  /// No description provided for @checkConfirmCheckOut.
  ///
  /// In en, this message translates to:
  /// **'Confirm Check Out'**
  String get checkConfirmCheckOut;

  /// No description provided for @checkTakeSelfieToContinue.
  ///
  /// In en, this message translates to:
  /// **'Take a selfie to continue.'**
  String get checkTakeSelfieToContinue;

  /// No description provided for @checkRejectedLocation.
  ///
  /// In en, this message translates to:
  /// **'Couldn\'\'t record your location'**
  String get checkRejectedLocation;

  /// No description provided for @checkRejectedAttendance.
  ///
  /// In en, this message translates to:
  /// **'Couldn\'\'t record your attendance'**
  String get checkRejectedAttendance;

  /// No description provided for @checkAreaInside.
  ///
  /// In en, this message translates to:
  /// **'You are within work area'**
  String get checkAreaInside;

  /// No description provided for @checkAreaOutside.
  ///
  /// In en, this message translates to:
  /// **'You appear to be outside the work area'**
  String get checkAreaOutside;

  /// No description provided for @checkNoSite.
  ///
  /// In en, this message translates to:
  /// **'No work site is configured for today.'**
  String get checkNoSite;

  /// No description provided for @checkDistanceFromSite.
  ///
  /// In en, this message translates to:
  /// **'{distance} from {site}'**
  String checkDistanceFromSite(String distance, String site);

  /// No description provided for @checkDistanceFromSiteLimit.
  ///
  /// In en, this message translates to:
  /// **'{distance} from {site} (limit {limit} m)'**
  String checkDistanceFromSiteLimit(String distance, String site, int limit);

  /// No description provided for @checkGpsAccuracy.
  ///
  /// In en, this message translates to:
  /// **'GPS accuracy ±{meters} m'**
  String checkGpsAccuracy(int meters);

  /// No description provided for @checkEstimatedOnDevice.
  ///
  /// In en, this message translates to:
  /// **'Estimated on this device'**
  String get checkEstimatedOnDevice;

  /// No description provided for @checkServerDecides.
  ///
  /// In en, this message translates to:
  /// **'The server makes the final decision. You can still try.'**
  String get checkServerDecides;

  /// No description provided for @checkSelfieRequired.
  ///
  /// In en, this message translates to:
  /// **'Selfie required'**
  String get checkSelfieRequired;

  /// No description provided for @checkSelfieRequiredMsg.
  ///
  /// In en, this message translates to:
  /// **'Your workplace asks for a photo with each check.'**
  String get checkSelfieRequiredMsg;

  /// No description provided for @checkTake.
  ///
  /// In en, this message translates to:
  /// **'Take'**
  String get checkTake;

  /// No description provided for @checkYourSelfie.
  ///
  /// In en, this message translates to:
  /// **'Your selfie'**
  String get checkYourSelfie;

  /// No description provided for @checkSelfieReady.
  ///
  /// In en, this message translates to:
  /// **'Selfie ready'**
  String get checkSelfieReady;

  /// No description provided for @checkSelfieReadyMsg.
  ///
  /// In en, this message translates to:
  /// **'It is sent with this check only.'**
  String get checkSelfieReadyMsg;

  /// No description provided for @checkRetake.
  ///
  /// In en, this message translates to:
  /// **'Retake'**
  String get checkRetake;

  /// No description provided for @checkRemove.
  ///
  /// In en, this message translates to:
  /// **'Remove'**
  String get checkRemove;

  /// No description provided for @checkSelfiePreviewSemantic.
  ///
  /// In en, this message translates to:
  /// **'Selfie preview'**
  String get checkSelfiePreviewSemantic;

  /// No description provided for @checkUsePhoto.
  ///
  /// In en, this message translates to:
  /// **'Use photo'**
  String get checkUsePhoto;

  /// No description provided for @checkPermDeniedTitle.
  ///
  /// In en, this message translates to:
  /// **'Location permission needed'**
  String get checkPermDeniedTitle;

  /// No description provided for @checkPermDeniedMsg.
  ///
  /// In en, this message translates to:
  /// **'PeopleOS uses your location only at the moment you check in or out, to confirm you are at work. It never tracks you in the background.'**
  String get checkPermDeniedMsg;

  /// No description provided for @checkPermBlockedTitle.
  ///
  /// In en, this message translates to:
  /// **'Location is blocked'**
  String get checkPermBlockedTitle;

  /// No description provided for @checkPermBlockedMsg.
  ///
  /// In en, this message translates to:
  /// **'Location access was turned off for PeopleOS. Open the app settings, allow Location, then come back.'**
  String get checkPermBlockedMsg;

  /// No description provided for @checkOpenAppSettings.
  ///
  /// In en, this message translates to:
  /// **'Open app settings'**
  String get checkOpenAppSettings;

  /// No description provided for @checkServiceOffTitle.
  ///
  /// In en, this message translates to:
  /// **'Location services are off'**
  String get checkServiceOffTitle;

  /// No description provided for @checkServiceOffMsg.
  ///
  /// In en, this message translates to:
  /// **'Turn on location (GPS) for this device so we can confirm you are at your work site.'**
  String get checkServiceOffMsg;

  /// No description provided for @checkOpenLocationSettings.
  ///
  /// In en, this message translates to:
  /// **'Open location settings'**
  String get checkOpenLocationSettings;

  /// No description provided for @checkUnavailableTitle.
  ///
  /// In en, this message translates to:
  /// **'Couldn\'\'t get your location'**
  String get checkUnavailableTitle;

  /// No description provided for @checkUnavailableMsg.
  ///
  /// In en, this message translates to:
  /// **'Move to an open area or near a window, then try again.'**
  String get checkUnavailableMsg;

  /// No description provided for @checkResultCheckedInAt.
  ///
  /// In en, this message translates to:
  /// **'Checked in at {time}'**
  String checkResultCheckedInAt(String time);

  /// No description provided for @checkResultCheckedOutAt.
  ///
  /// In en, this message translates to:
  /// **'Checked out at {time}'**
  String checkResultCheckedOutAt(String time);

  /// No description provided for @checkResultSavedOnDevice.
  ///
  /// In en, this message translates to:
  /// **'Saved on this device'**
  String get checkResultSavedOnDevice;

  /// No description provided for @checkResultOfflineInBody.
  ///
  /// In en, this message translates to:
  /// **'Checked in at {time} (pending sync). It will be sent automatically as soon as the connection is back. Nothing is lost.'**
  String checkResultOfflineInBody(String time);

  /// No description provided for @checkResultOfflineOutBody.
  ///
  /// In en, this message translates to:
  /// **'Checked out at {time} (pending sync). It will be sent automatically as soon as the connection is back. Nothing is lost.'**
  String checkResultOfflineOutBody(String time);

  /// No description provided for @checkWorkSiteFallback.
  ///
  /// In en, this message translates to:
  /// **'work site'**
  String get checkWorkSiteFallback;

  /// No description provided for @checkSummaryRecorded.
  ///
  /// In en, this message translates to:
  /// **'Your attendance has been recorded.'**
  String get checkSummaryRecorded;

  /// No description provided for @checkSummaryLate.
  ///
  /// In en, this message translates to:
  /// **'You are {duration} late today. Have a good day!'**
  String checkSummaryLate(String duration);

  /// No description provided for @checkSummaryOnTime.
  ///
  /// In en, this message translates to:
  /// **'You are on time. Have a great day!'**
  String get checkSummaryOnTime;

  /// No description provided for @checkSummaryWorked.
  ///
  /// In en, this message translates to:
  /// **'You worked {duration} today. See you tomorrow!'**
  String checkSummaryWorked(String duration);

  /// No description provided for @checkMapLocating.
  ///
  /// In en, this message translates to:
  /// **'Locating you'**
  String get checkMapLocating;

  /// No description provided for @checkMapGeneric.
  ///
  /// In en, this message translates to:
  /// **'Location map'**
  String get checkMapGeneric;

  /// No description provided for @checkMapRelative.
  ///
  /// In en, this message translates to:
  /// **'Map showing your position relative to {site}'**
  String checkMapRelative(String site);

  /// No description provided for @commonCancel.
  ///
  /// In en, this message translates to:
  /// **'Cancel'**
  String get commonCancel;

  /// No description provided for @commonOk.
  ///
  /// In en, this message translates to:
  /// **'OK'**
  String get commonOk;

  /// No description provided for @commonTryAgain.
  ///
  /// In en, this message translates to:
  /// **'Try again'**
  String get commonTryAgain;

  /// No description provided for @commonBack.
  ///
  /// In en, this message translates to:
  /// **'Back'**
  String get commonBack;

  /// No description provided for @commonDone.
  ///
  /// In en, this message translates to:
  /// **'Done'**
  String get commonDone;

  /// No description provided for @commonSave.
  ///
  /// In en, this message translates to:
  /// **'Save'**
  String get commonSave;

  /// No description provided for @commonSubmit.
  ///
  /// In en, this message translates to:
  /// **'Submit'**
  String get commonSubmit;

  /// No description provided for @commonClose.
  ///
  /// In en, this message translates to:
  /// **'Close'**
  String get commonClose;

  /// No description provided for @commonRetry.
  ///
  /// In en, this message translates to:
  /// **'Retry'**
  String get commonRetry;

  /// No description provided for @commonSoon.
  ///
  /// In en, this message translates to:
  /// **'Soon'**
  String get commonSoon;

  /// No description provided for @commonYes.
  ///
  /// In en, this message translates to:
  /// **'Yes'**
  String get commonYes;

  /// No description provided for @commonNo.
  ///
  /// In en, this message translates to:
  /// **'No'**
  String get commonNo;

  /// No description provided for @commonClear.
  ///
  /// In en, this message translates to:
  /// **'Clear {label}'**
  String commonClear(String label);

  /// No description provided for @commonSelect.
  ///
  /// In en, this message translates to:
  /// **'Select'**
  String get commonSelect;

  /// No description provided for @commonSelectItem.
  ///
  /// In en, this message translates to:
  /// **'Select an item'**
  String get commonSelectItem;

  /// No description provided for @commonLoading.
  ///
  /// In en, this message translates to:
  /// **'Loading'**
  String get commonLoading;

  /// No description provided for @commonProfilePhotoOf.
  ///
  /// In en, this message translates to:
  /// **'Profile photo of {name}'**
  String commonProfilePhotoOf(String name);

  /// No description provided for @commonSyncStatus.
  ///
  /// In en, this message translates to:
  /// **'Sync status: {label}'**
  String commonSyncStatus(String label);

  /// No description provided for @commonOfflineTitle.
  ///
  /// In en, this message translates to:
  /// **'You\'\'re offline.'**
  String get commonOfflineTitle;

  /// No description provided for @commonOfflineSaved.
  ///
  /// In en, this message translates to:
  /// **'Attendance is saved on this device and syncs automatically.'**
  String get commonOfflineSaved;

  /// No description provided for @commonOfflineWaiting.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 action waiting to sync.} other{{count} actions waiting to sync.}}'**
  String commonOfflineWaiting(int count);

  /// No description provided for @navHome.
  ///
  /// In en, this message translates to:
  /// **'Home'**
  String get navHome;

  /// No description provided for @navAttendance.
  ///
  /// In en, this message translates to:
  /// **'Attendance'**
  String get navAttendance;

  /// No description provided for @navRequests.
  ///
  /// In en, this message translates to:
  /// **'Requests'**
  String get navRequests;

  /// No description provided for @navPayslip.
  ///
  /// In en, this message translates to:
  /// **'Payslip'**
  String get navPayslip;

  /// No description provided for @navProfile.
  ///
  /// In en, this message translates to:
  /// **'Profile'**
  String get navProfile;

  /// No description provided for @badgeActive.
  ///
  /// In en, this message translates to:
  /// **'Active'**
  String get badgeActive;

  /// No description provided for @badgePending.
  ///
  /// In en, this message translates to:
  /// **'Pending'**
  String get badgePending;

  /// No description provided for @badgeApproved.
  ///
  /// In en, this message translates to:
  /// **'Approved'**
  String get badgeApproved;

  /// No description provided for @badgeRejected.
  ///
  /// In en, this message translates to:
  /// **'Rejected'**
  String get badgeRejected;

  /// No description provided for @badgeExpired.
  ///
  /// In en, this message translates to:
  /// **'Expired'**
  String get badgeExpired;

  /// No description provided for @badgeLate.
  ///
  /// In en, this message translates to:
  /// **'Late'**
  String get badgeLate;

  /// No description provided for @badgeOnTime.
  ///
  /// In en, this message translates to:
  /// **'On time'**
  String get badgeOnTime;

  /// No description provided for @badgeWorking.
  ///
  /// In en, this message translates to:
  /// **'WORKING'**
  String get badgeWorking;

  /// No description provided for @badgeOffline.
  ///
  /// In en, this message translates to:
  /// **'Offline'**
  String get badgeOffline;

  /// No description provided for @errOfflineTitle.
  ///
  /// In en, this message translates to:
  /// **'You\'\'re offline'**
  String get errOfflineTitle;

  /// No description provided for @errTimeoutTitle.
  ///
  /// In en, this message translates to:
  /// **'The server is slow to respond'**
  String get errTimeoutTitle;

  /// No description provided for @errForbiddenTitle.
  ///
  /// In en, this message translates to:
  /// **'Access denied'**
  String get errForbiddenTitle;

  /// No description provided for @errNotFoundTitle.
  ///
  /// In en, this message translates to:
  /// **'Not found'**
  String get errNotFoundTitle;

  /// No description provided for @errServerTitle.
  ///
  /// In en, this message translates to:
  /// **'Server problem'**
  String get errServerTitle;

  /// No description provided for @errGenericTitle.
  ///
  /// In en, this message translates to:
  /// **'Something went wrong'**
  String get errGenericTitle;

  /// No description provided for @errPleaseTryAgain.
  ///
  /// In en, this message translates to:
  /// **'Please try again.'**
  String get errPleaseTryAgain;

  /// No description provided for @errNoConnection.
  ///
  /// In en, this message translates to:
  /// **'No internet connection. Check your network and try again.'**
  String get errNoConnection;

  /// No description provided for @errTimeout.
  ///
  /// In en, this message translates to:
  /// **'The server took too long to respond.'**
  String get errTimeout;

  /// No description provided for @errServer.
  ///
  /// In en, this message translates to:
  /// **'Something went wrong on the server. Please try again.'**
  String get errServer;

  /// No description provided for @errForbidden.
  ///
  /// In en, this message translates to:
  /// **'You don\'\'t have permission to do this.'**
  String get errForbidden;

  /// No description provided for @errNotFound.
  ///
  /// In en, this message translates to:
  /// **'We couldn\'\'t find what you were looking for.'**
  String get errNotFound;

  /// No description provided for @errUnauthorized.
  ///
  /// In en, this message translates to:
  /// **'Your session has expired. Please sign in again.'**
  String get errUnauthorized;

  /// No description provided for @errRateLimited.
  ///
  /// In en, this message translates to:
  /// **'Too many requests. Please try again in a moment.'**
  String get errRateLimited;

  /// No description provided for @errUnknown.
  ///
  /// In en, this message translates to:
  /// **'Unexpected error. Please try again.'**
  String get errUnknown;

  /// No description provided for @errAttOutsideGeofenceDistance.
  ///
  /// In en, this message translates to:
  /// **'You are {distance} m from the work site. You must be within {radius} m to record attendance.'**
  String errAttOutsideGeofenceDistance(int distance, int radius);

  /// No description provided for @errAttOutsideGeofence.
  ///
  /// In en, this message translates to:
  /// **'You are outside the allowed work area.'**
  String get errAttOutsideGeofence;

  /// No description provided for @errAttGpsRequired.
  ///
  /// In en, this message translates to:
  /// **'Location is required to record attendance.'**
  String get errAttGpsRequired;

  /// No description provided for @errAttGpsAccuracyTooLow.
  ///
  /// In en, this message translates to:
  /// **'GPS accuracy is too low. Move to an open area and try again.'**
  String get errAttGpsAccuracyTooLow;

  /// No description provided for @errAttMockLocation.
  ///
  /// In en, this message translates to:
  /// **'A mock location was detected. Turn it off and try again.'**
  String get errAttMockLocation;

  /// No description provided for @errAttLocationStale.
  ///
  /// In en, this message translates to:
  /// **'The location fix is too old. Please try again.'**
  String get errAttLocationStale;

  /// No description provided for @errAttSelfieRequired.
  ///
  /// In en, this message translates to:
  /// **'A selfie is required for this check.'**
  String get errAttSelfieRequired;

  /// No description provided for @errAttAlreadyCheckedIn.
  ///
  /// In en, this message translates to:
  /// **'You are already checked in.'**
  String get errAttAlreadyCheckedIn;

  /// No description provided for @errAttAlreadyCheckedOut.
  ///
  /// In en, this message translates to:
  /// **'You have already checked out for this day.'**
  String get errAttAlreadyCheckedOut;

  /// No description provided for @errAttNotCheckedIn.
  ///
  /// In en, this message translates to:
  /// **'You need to check in before checking out.'**
  String get errAttNotCheckedIn;

  /// No description provided for @errAttCheckOutTooLate.
  ///
  /// In en, this message translates to:
  /// **'The check-out window for this shift has closed.'**
  String get errAttCheckOutTooLate;

  /// No description provided for @errAttOnLeave.
  ///
  /// In en, this message translates to:
  /// **'You are on approved leave today, so check-in is not needed.'**
  String get errAttOnLeave;

  /// No description provided for @errAttNoSchedule.
  ///
  /// In en, this message translates to:
  /// **'You have no schedule for today.'**
  String get errAttNoSchedule;

  /// No description provided for @errAttNotWorkingDay.
  ///
  /// In en, this message translates to:
  /// **'Today is not a working day for you.'**
  String get errAttNotWorkingDay;

  /// No description provided for @errAttOfflineEventTooOld.
  ///
  /// In en, this message translates to:
  /// **'This action was saved offline for too long to be accepted. Ask for an attendance correction instead.'**
  String get errAttOfflineEventTooOld;

  /// No description provided for @errAttInvalidEventTime.
  ///
  /// In en, this message translates to:
  /// **'The time recorded on this device was not accepted. Ask for an attendance correction instead.'**
  String get errAttInvalidEventTime;

  /// No description provided for @errAttEmployeeTerminated.
  ///
  /// In en, this message translates to:
  /// **'Your employment has ended. Attendance is disabled.'**
  String get errAttEmployeeTerminated;

  /// No description provided for @errAttEmployeeNotActive.
  ///
  /// In en, this message translates to:
  /// **'Your employee profile is not active yet.'**
  String get errAttEmployeeNotActive;

  /// No description provided for @errAttAccountDisabled.
  ///
  /// In en, this message translates to:
  /// **'Your account has been disabled. Contact HR.'**
  String get errAttAccountDisabled;

  /// No description provided for @errAttIdempotencyConflict.
  ///
  /// In en, this message translates to:
  /// **'This action conflicts with an earlier one. Please refresh.'**
  String get errAttIdempotencyConflict;

  /// No description provided for @errFileMissing.
  ///
  /// In en, this message translates to:
  /// **'The file is missing on the server. Please tell HR.'**
  String get errFileMissing;

  /// No description provided for @errFileUnavailable.
  ///
  /// In en, this message translates to:
  /// **'This file is no longer available.'**
  String get errFileUnavailable;

  /// No description provided for @errFileForbidden.
  ///
  /// In en, this message translates to:
  /// **'You are not allowed to open this file.'**
  String get errFileForbidden;

  /// No description provided for @errFileOpenFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not open the file on this device.'**
  String get errFileOpenFailed;

  /// No description provided for @commonSharedFile.
  ///
  /// In en, this message translates to:
  /// **'Opened share sheet for {name}'**
  String commonSharedFile(String name);

  /// No description provided for @commonDownloadedFile.
  ///
  /// In en, this message translates to:
  /// **'Downloaded {name}'**
  String commonDownloadedFile(String name);

  /// No description provided for @documentsTitle.
  ///
  /// In en, this message translates to:
  /// **'Documents'**
  String get documentsTitle;

  /// No description provided for @documentsDetailTitle.
  ///
  /// In en, this message translates to:
  /// **'Document'**
  String get documentsDetailTitle;

  /// No description provided for @documentsSelectPrompt.
  ///
  /// In en, this message translates to:
  /// **'Select a document'**
  String get documentsSelectPrompt;

  /// No description provided for @documentsEmptyTitle.
  ///
  /// In en, this message translates to:
  /// **'No documents'**
  String get documentsEmptyTitle;

  /// No description provided for @documentsEmptyFiltered.
  ///
  /// In en, this message translates to:
  /// **'Nothing matches these filters.'**
  String get documentsEmptyFiltered;

  /// No description provided for @documentsEmptyDefault.
  ///
  /// In en, this message translates to:
  /// **'Documents shared with you by HR appear here.'**
  String get documentsEmptyDefault;

  /// No description provided for @documentsFilterAll.
  ///
  /// In en, this message translates to:
  /// **'All'**
  String get documentsFilterAll;

  /// No description provided for @documentsFilterSemantics.
  ///
  /// In en, this message translates to:
  /// **'Filter: {label}'**
  String documentsFilterSemantics(String label);

  /// No description provided for @documentsStatusActive.
  ///
  /// In en, this message translates to:
  /// **'Active'**
  String get documentsStatusActive;

  /// No description provided for @documentsStatusExpiring.
  ///
  /// In en, this message translates to:
  /// **'Expiring'**
  String get documentsStatusExpiring;

  /// No description provided for @documentsStatusExpired.
  ///
  /// In en, this message translates to:
  /// **'Expired'**
  String get documentsStatusExpired;

  /// No description provided for @documentsStatusArchived.
  ///
  /// In en, this message translates to:
  /// **'Archived'**
  String get documentsStatusArchived;

  /// No description provided for @documentsTypeCompany.
  ///
  /// In en, this message translates to:
  /// **'{type} · Company'**
  String documentsTypeCompany(String type);

  /// No description provided for @documentsExpiredAgo.
  ///
  /// In en, this message translates to:
  /// **'{n, plural, =1{Expired 1 day ago} other{Expired {n} days ago}}'**
  String documentsExpiredAgo(int n);

  /// No description provided for @documentsExpiresToday.
  ///
  /// In en, this message translates to:
  /// **'Expires today'**
  String get documentsExpiresToday;

  /// No description provided for @documentsExpiresTomorrow.
  ///
  /// In en, this message translates to:
  /// **'Expires tomorrow'**
  String get documentsExpiresTomorrow;

  /// No description provided for @documentsExpiresIn.
  ///
  /// In en, this message translates to:
  /// **'Expires in {n} days'**
  String documentsExpiresIn(int n);

  /// No description provided for @documentsOwner.
  ///
  /// In en, this message translates to:
  /// **'Owner'**
  String get documentsOwner;

  /// No description provided for @documentsOwnerCompany.
  ///
  /// In en, this message translates to:
  /// **'Company document'**
  String get documentsOwnerCompany;

  /// No description provided for @documentsOwnerYou.
  ///
  /// In en, this message translates to:
  /// **'You'**
  String get documentsOwnerYou;

  /// No description provided for @documentsExpiryDate.
  ///
  /// In en, this message translates to:
  /// **'Expiry date'**
  String get documentsExpiryDate;

  /// No description provided for @documentsNoExpiry.
  ///
  /// In en, this message translates to:
  /// **'No expiry'**
  String get documentsNoExpiry;

  /// No description provided for @documentsFile.
  ///
  /// In en, this message translates to:
  /// **'File'**
  String get documentsFile;

  /// No description provided for @documentsFileValue.
  ///
  /// In en, this message translates to:
  /// **'{name} · {size}'**
  String documentsFileValue(String name, String size);

  /// No description provided for @documentsUploaded.
  ///
  /// In en, this message translates to:
  /// **'Uploaded'**
  String get documentsUploaded;

  /// No description provided for @documentsDownloadOpen.
  ///
  /// In en, this message translates to:
  /// **'Download / open'**
  String get documentsDownloadOpen;

  /// No description provided for @documentsFileSizeBytes.
  ///
  /// In en, this message translates to:
  /// **'{n} B'**
  String documentsFileSizeBytes(int n);

  /// No description provided for @documentsFileSizeKb.
  ///
  /// In en, this message translates to:
  /// **'{n} KB'**
  String documentsFileSizeKb(String n);

  /// No description provided for @documentsFileSizeMb.
  ///
  /// In en, this message translates to:
  /// **'{n} MB'**
  String documentsFileSizeMb(String n);

  /// No description provided for @documentsSemantics.
  ///
  /// In en, this message translates to:
  /// **'{title}, {type}, {status}'**
  String documentsSemantics(String title, String type, String status);

  /// No description provided for @documentsSemanticsExpiry.
  ///
  /// In en, this message translates to:
  /// **'{title}, {type}, {status}, {expiry}'**
  String documentsSemanticsExpiry(
    String title,
    String type,
    String status,
    String expiry,
  );

  /// No description provided for @homeAttendance.
  ///
  /// In en, this message translates to:
  /// **'Attendance'**
  String get homeAttendance;

  /// No description provided for @homeQuickLeave.
  ///
  /// In en, this message translates to:
  /// **'Leave'**
  String get homeQuickLeave;

  /// No description provided for @homeQuickPayslip.
  ///
  /// In en, this message translates to:
  /// **'Payslip'**
  String get homeQuickPayslip;

  /// No description provided for @homeQuickAttendance.
  ///
  /// In en, this message translates to:
  /// **'Attendance'**
  String get homeQuickAttendance;

  /// No description provided for @homeQuickRequests.
  ///
  /// In en, this message translates to:
  /// **'Requests'**
  String get homeQuickRequests;

  /// No description provided for @homeQuickDocuments.
  ///
  /// In en, this message translates to:
  /// **'Documents'**
  String get homeQuickDocuments;

  /// No description provided for @homeQuickMore.
  ///
  /// In en, this message translates to:
  /// **'More'**
  String get homeQuickMore;

  /// No description provided for @homeLoadingToday.
  ///
  /// In en, this message translates to:
  /// **'Loading today'**
  String get homeLoadingToday;

  /// No description provided for @homeSavedWaitingSync.
  ///
  /// In en, this message translates to:
  /// **'Saved on this device. Waiting to sync.'**
  String get homeSavedWaitingSync;

  /// No description provided for @homeFailedActions.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 attendance action could not sync. Review} other{{count} attendance actions could not sync. Review}}'**
  String homeFailedActions(int count);

  /// No description provided for @homeStaleSchedule.
  ///
  /// In en, this message translates to:
  /// **'Showing your last known schedule while offline.'**
  String get homeStaleSchedule;

  /// No description provided for @homeShiftStarts.
  ///
  /// In en, this message translates to:
  /// **'Shift starts {time}'**
  String homeShiftStarts(String time);

  /// No description provided for @homeNotCheckedIn.
  ///
  /// In en, this message translates to:
  /// **'Not checked in'**
  String get homeNotCheckedIn;

  /// No description provided for @homeReadyWhenYouAre.
  ///
  /// In en, this message translates to:
  /// **'Ready when you are.'**
  String get homeReadyWhenYouAre;

  /// No description provided for @homeCheckedInTitle.
  ///
  /// In en, this message translates to:
  /// **'You\'\'re Checked In'**
  String get homeCheckedInTitle;

  /// No description provided for @homeBadgeWorking.
  ///
  /// In en, this message translates to:
  /// **'WORKING'**
  String get homeBadgeWorking;

  /// No description provided for @homeCheckedOutTitle.
  ///
  /// In en, this message translates to:
  /// **'Checked out'**
  String get homeCheckedOutTitle;

  /// No description provided for @homeCheckedOutWorked.
  ///
  /// In en, this message translates to:
  /// **'{inTime} - {outTime} · {duration} on the clock'**
  String homeCheckedOutWorked(String inTime, String outTime, String duration);

  /// No description provided for @homeWaitingReview.
  ///
  /// In en, this message translates to:
  /// **'Waiting for review'**
  String get homeWaitingReview;

  /// No description provided for @homeWaitingReviewMsg.
  ///
  /// In en, this message translates to:
  /// **'Your attendance for today needs a manager review before it is final.'**
  String get homeWaitingReviewMsg;

  /// No description provided for @homeCorrectionPending.
  ///
  /// In en, this message translates to:
  /// **'Correction pending'**
  String get homeCorrectionPending;

  /// No description provided for @homeCorrectionPendingMsg.
  ///
  /// In en, this message translates to:
  /// **'Your correction request is waiting for approval.'**
  String get homeCorrectionPendingMsg;

  /// No description provided for @homeBadgePending.
  ///
  /// In en, this message translates to:
  /// **'Pending'**
  String get homeBadgePending;

  /// No description provided for @homeRejectedTitle.
  ///
  /// In en, this message translates to:
  /// **'Attendance rejected'**
  String get homeRejectedTitle;

  /// No description provided for @homeRejectedMsg.
  ///
  /// In en, this message translates to:
  /// **'Your last correction was rejected. Contact your manager.'**
  String get homeRejectedMsg;

  /// No description provided for @homeBadgeRejected.
  ///
  /// In en, this message translates to:
  /// **'Rejected'**
  String get homeBadgeRejected;

  /// No description provided for @homeNotWorkingDay.
  ///
  /// In en, this message translates to:
  /// **'Not a working day'**
  String get homeNotWorkingDay;

  /// No description provided for @homeDayOffMsg.
  ///
  /// In en, this message translates to:
  /// **'Enjoy your day off. Check-in is not needed today.'**
  String get homeDayOffMsg;

  /// No description provided for @homeBadgeDayOff.
  ///
  /// In en, this message translates to:
  /// **'Day off'**
  String get homeBadgeDayOff;

  /// No description provided for @homeOnLeaveTitle.
  ///
  /// In en, this message translates to:
  /// **'On leave today'**
  String get homeOnLeaveTitle;

  /// No description provided for @homeOnLeaveMsg.
  ///
  /// In en, this message translates to:
  /// **'Enjoy your time off. Check-in is not needed while your leave is approved.'**
  String get homeOnLeaveMsg;

  /// No description provided for @homeBadgeOnLeave.
  ///
  /// In en, this message translates to:
  /// **'On leave'**
  String get homeBadgeOnLeave;

  /// No description provided for @homeNoScheduleTitle.
  ///
  /// In en, this message translates to:
  /// **'No schedule for today'**
  String get homeNoScheduleTitle;

  /// No description provided for @homeNoScheduleMsg.
  ///
  /// In en, this message translates to:
  /// **'No shift is assigned to you yet. Ask HR or your manager to assign one.'**
  String get homeNoScheduleMsg;

  /// No description provided for @homeBadgeNoShift.
  ///
  /// In en, this message translates to:
  /// **'No shift'**
  String get homeBadgeNoShift;

  /// No description provided for @homeBlockedTitle.
  ///
  /// In en, this message translates to:
  /// **'Attendance unavailable'**
  String get homeBlockedTitle;

  /// No description provided for @homeBlockedTerminatedMsg.
  ///
  /// In en, this message translates to:
  /// **'Your employment has ended, so check-in is disabled. Your history stays available.'**
  String get homeBlockedTerminatedMsg;

  /// No description provided for @homeBlockedInactiveMsg.
  ///
  /// In en, this message translates to:
  /// **'Your employee profile is not active yet. Contact HR.'**
  String get homeBlockedInactiveMsg;

  /// No description provided for @homeBadgeTerminated.
  ///
  /// In en, this message translates to:
  /// **'Terminated'**
  String get homeBadgeTerminated;

  /// No description provided for @homeBadgeInactive.
  ///
  /// In en, this message translates to:
  /// **'Inactive'**
  String get homeBadgeInactive;

  /// No description provided for @homeSinceTime.
  ///
  /// In en, this message translates to:
  /// **'Since {time}'**
  String homeSinceTime(String time);

  /// No description provided for @homeTimeWorkedSemantic.
  ///
  /// In en, this message translates to:
  /// **'Time worked {duration}'**
  String homeTimeWorkedSemantic(String duration);

  /// No description provided for @homeCheckIn.
  ///
  /// In en, this message translates to:
  /// **'Check In'**
  String get homeCheckIn;

  /// No description provided for @homeCheckOut.
  ///
  /// In en, this message translates to:
  /// **'Check Out'**
  String get homeCheckOut;

  /// No description provided for @homeViewRequests.
  ///
  /// In en, this message translates to:
  /// **'View requests'**
  String get homeViewRequests;

  /// No description provided for @homeDepartment.
  ///
  /// In en, this message translates to:
  /// **'{name} Department'**
  String homeDepartment(String name);

  /// No description provided for @homeNotifications.
  ///
  /// In en, this message translates to:
  /// **'Notifications'**
  String get homeNotifications;

  /// No description provided for @homeUnreadCount.
  ///
  /// In en, this message translates to:
  /// **'{count} unread'**
  String homeUnreadCount(int count);

  /// No description provided for @homeRecentActivity.
  ///
  /// In en, this message translates to:
  /// **'Recent activity'**
  String get homeRecentActivity;

  /// No description provided for @homeViewAll.
  ///
  /// In en, this message translates to:
  /// **'View all'**
  String get homeViewAll;

  /// No description provided for @homeActivityUnavailable.
  ///
  /// In en, this message translates to:
  /// **'Activity is unavailable right now.'**
  String get homeActivityUnavailable;

  /// No description provided for @homeNoAttendanceThisMonth.
  ///
  /// In en, this message translates to:
  /// **'No attendance recorded yet this month.'**
  String get homeNoAttendanceThisMonth;

  /// No description provided for @homeNoUpcomingLeave.
  ///
  /// In en, this message translates to:
  /// **'No upcoming leave'**
  String get homeNoUpcomingLeave;

  /// No description provided for @homeNextLeave.
  ///
  /// In en, this message translates to:
  /// **'Next leave'**
  String get homeNextLeave;

  /// No description provided for @homePlanTimeOff.
  ///
  /// In en, this message translates to:
  /// **'Plan time off from Requests'**
  String get homePlanTimeOff;

  /// No description provided for @homePendingCount.
  ///
  /// In en, this message translates to:
  /// **'{count} pending'**
  String homePendingCount(int count);

  /// No description provided for @homeSummarySemantic.
  ///
  /// In en, this message translates to:
  /// **'{title}. {subtitle}. {count, plural, =1{1 pending request} other{{count} pending requests}}.'**
  String homeSummarySemantic(String title, String subtitle, int count);

  /// No description provided for @homeUpdates.
  ///
  /// In en, this message translates to:
  /// **'Updates'**
  String get homeUpdates;

  /// No description provided for @homePayslipAvailable.
  ///
  /// In en, this message translates to:
  /// **'Payslip available'**
  String get homePayslipAvailable;

  /// No description provided for @homeDocsNeedAttention.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 document needs attention} other{{count} documents need attention}}'**
  String homeDocsNeedAttention(int count);

  /// No description provided for @homeExpiringSoon.
  ///
  /// In en, this message translates to:
  /// **'{count} expiring soon'**
  String homeExpiringSoon(int count);

  /// No description provided for @homeExpired.
  ///
  /// In en, this message translates to:
  /// **'{count} expired'**
  String homeExpired(int count);

  /// No description provided for @homeTodaysShift.
  ///
  /// In en, this message translates to:
  /// **'Today\'\'s Shift'**
  String get homeTodaysShift;

  /// No description provided for @homeNotStarted.
  ///
  /// In en, this message translates to:
  /// **'Not started'**
  String get homeNotStarted;

  /// No description provided for @homeOnSchedule.
  ///
  /// In en, this message translates to:
  /// **'On Schedule'**
  String get homeOnSchedule;

  /// No description provided for @homeNoShiftAssigned.
  ///
  /// In en, this message translates to:
  /// **'No shift assigned'**
  String get homeNoShiftAssigned;

  /// No description provided for @homeRestDay.
  ///
  /// In en, this message translates to:
  /// **'Rest day'**
  String get homeRestDay;

  /// No description provided for @leaveErrOverlap.
  ///
  /// In en, this message translates to:
  /// **'These dates overlap another leave request that is pending or approved.'**
  String get leaveErrOverlap;

  /// No description provided for @leaveErrInsufficientBalance.
  ///
  /// In en, this message translates to:
  /// **'{available, plural, =1{Not enough balance: you asked for {requestedDays} but only {availableDays} is available.} other{Not enough balance: you asked for {requestedDays} but only {availableDays} are available.}}'**
  String leaveErrInsufficientBalance(
    num available,
    String requestedDays,
    String availableDays,
  );

  /// No description provided for @leaveErrInsufficientBalanceGeneric.
  ///
  /// In en, this message translates to:
  /// **'Not enough leave balance for these dates.'**
  String get leaveErrInsufficientBalanceGeneric;

  /// No description provided for @leaveErrNoticeTooShort.
  ///
  /// In en, this message translates to:
  /// **'This leave type needs more advance notice. Choose a later start date.'**
  String get leaveErrNoticeTooShort;

  /// No description provided for @leaveErrTooFarInPast.
  ///
  /// In en, this message translates to:
  /// **'This leave starts too far in the past for this leave type.'**
  String get leaveErrTooFarInPast;

  /// No description provided for @leaveErrNoWorkingDays.
  ///
  /// In en, this message translates to:
  /// **'The selected dates contain no working days (weekends and holidays are not counted).'**
  String get leaveErrNoWorkingDays;

  /// No description provided for @leaveErrSpansYears.
  ///
  /// In en, this message translates to:
  /// **'A request cannot cross into the next leave year. Split it into two requests.'**
  String get leaveErrSpansYears;

  /// No description provided for @leaveErrTooLong.
  ///
  /// In en, this message translates to:
  /// **'This request is longer than the maximum allowed for this leave type. Shorten the dates or split it into separate requests.'**
  String get leaveErrTooLong;

  /// No description provided for @leaveErrHalfDaySingleDate.
  ///
  /// In en, this message translates to:
  /// **'A half day must start and end on the same date.'**
  String get leaveErrHalfDaySingleDate;

  /// No description provided for @leaveErrHalfDayNotAllowed.
  ///
  /// In en, this message translates to:
  /// **'This leave type does not allow half days.'**
  String get leaveErrHalfDayNotAllowed;

  /// No description provided for @leaveErrInvalidDateRange.
  ///
  /// In en, this message translates to:
  /// **'The end date must be on or after the start date.'**
  String get leaveErrInvalidDateRange;

  /// No description provided for @leaveErrTypeUnavailable.
  ///
  /// In en, this message translates to:
  /// **'This leave type is no longer available.'**
  String get leaveErrTypeUnavailable;

  /// No description provided for @leaveErrPolicyMissing.
  ///
  /// In en, this message translates to:
  /// **'This leave type has no policy for you yet. Contact HR.'**
  String get leaveErrPolicyMissing;

  /// No description provided for @leaveErrAlreadyStarted.
  ///
  /// In en, this message translates to:
  /// **'This leave has already started and can no longer be cancelled.'**
  String get leaveErrAlreadyStarted;

  /// No description provided for @leaveErrRequestNotPending.
  ///
  /// In en, this message translates to:
  /// **'This request was already decided or cancelled. Pull to refresh.'**
  String get leaveErrRequestNotPending;

  /// No description provided for @leaveErrEmployeeTerminated.
  ///
  /// In en, this message translates to:
  /// **'Your employment has ended, so new leave cannot be requested.'**
  String get leaveErrEmployeeTerminated;

  /// No description provided for @leaveErrEmployeeNotActive.
  ///
  /// In en, this message translates to:
  /// **'Your employee profile is not active yet. Contact HR.'**
  String get leaveErrEmployeeNotActive;

  /// No description provided for @leaveStatusDraft.
  ///
  /// In en, this message translates to:
  /// **'Draft'**
  String get leaveStatusDraft;

  /// No description provided for @leaveStatusPending.
  ///
  /// In en, this message translates to:
  /// **'Pending'**
  String get leaveStatusPending;

  /// No description provided for @leaveStatusApproved.
  ///
  /// In en, this message translates to:
  /// **'Approved'**
  String get leaveStatusApproved;

  /// No description provided for @leaveStatusRejected.
  ///
  /// In en, this message translates to:
  /// **'Rejected'**
  String get leaveStatusRejected;

  /// No description provided for @leaveStatusCancelled.
  ///
  /// In en, this message translates to:
  /// **'Cancelled'**
  String get leaveStatusCancelled;

  /// No description provided for @leaveHalfDayAm.
  ///
  /// In en, this message translates to:
  /// **'Morning (AM)'**
  String get leaveHalfDayAm;

  /// No description provided for @leaveHalfDayPm.
  ///
  /// In en, this message translates to:
  /// **'Afternoon (PM)'**
  String get leaveHalfDayPm;

  /// No description provided for @leaveHintUnlimited.
  ///
  /// In en, this message translates to:
  /// **'Not limited by a yearly balance'**
  String get leaveHintUnlimited;

  /// No description provided for @leaveHintPerYear.
  ///
  /// In en, this message translates to:
  /// **'{days} per year'**
  String leaveHintPerYear(String days);

  /// No description provided for @leaveHintNotice.
  ///
  /// In en, this message translates to:
  /// **'{n, plural, =1{Request at least 1 day ahead} other{Request at least {n} days ahead}}'**
  String leaveHintNotice(int n);

  /// No description provided for @leaveHintMaxPast.
  ///
  /// In en, this message translates to:
  /// **'{n, plural, =1{Can start up to 1 day in the past} other{Can start up to {n} days in the past}}'**
  String leaveHintMaxPast(int n);

  /// No description provided for @leaveHintMaxConsecutive.
  ///
  /// In en, this message translates to:
  /// **'Max {n} working days per request'**
  String leaveHintMaxConsecutive(int n);

  /// No description provided for @leaveHintHalfDay.
  ///
  /// In en, this message translates to:
  /// **'Half days allowed'**
  String get leaveHintHalfDay;

  /// No description provided for @leaveHintReasonRequired.
  ///
  /// In en, this message translates to:
  /// **'Reason required'**
  String get leaveHintReasonRequired;

  /// No description provided for @leaveDurationCountedOnSubmit.
  ///
  /// In en, this message translates to:
  /// **'Counted when submitted'**
  String get leaveDurationCountedOnSubmit;

  /// No description provided for @leaveDurationWithHalf.
  ///
  /// In en, this message translates to:
  /// **'{days} · {half}'**
  String leaveDurationWithHalf(String days, String half);

  /// No description provided for @leaveBalanceDaysUsedYear.
  ///
  /// In en, this message translates to:
  /// **'days used this year'**
  String get leaveBalanceDaysUsedYear;

  /// No description provided for @leaveBalanceOfAvailable.
  ///
  /// In en, this message translates to:
  /// **'of {total} available'**
  String leaveBalanceOfAvailable(String total);

  /// No description provided for @leaveBalancePending.
  ///
  /// In en, this message translates to:
  /// **'{n} pending'**
  String leaveBalancePending(String n);

  /// No description provided for @leaveBalanceNoLimit.
  ///
  /// In en, this message translates to:
  /// **'No yearly limit'**
  String get leaveBalanceNoLimit;

  /// No description provided for @leaveBalanceUsedPending.
  ///
  /// In en, this message translates to:
  /// **'{used} used · {pending} pending'**
  String leaveBalanceUsedPending(String used, String pending);

  /// No description provided for @leaveBalanceSemantics.
  ///
  /// In en, this message translates to:
  /// **'{type}: {headline} {caption}. {footer}.'**
  String leaveBalanceSemantics(
    String type,
    String headline,
    String caption,
    String footer,
  );

  /// No description provided for @leaveCardSemantics.
  ///
  /// In en, this message translates to:
  /// **'{type}, {dates}, {duration}, {status}'**
  String leaveCardSemantics(
    String type,
    String dates,
    String duration,
    String status,
  );

  /// No description provided for @leaveCardSubtitle.
  ///
  /// In en, this message translates to:
  /// **'{type} · {duration}'**
  String leaveCardSubtitle(String type, String duration);

  /// No description provided for @leavePreviewIntro.
  ///
  /// In en, this message translates to:
  /// **'Choose a type and dates to see how many working days this uses.'**
  String get leavePreviewIntro;

  /// No description provided for @leavePreviewCalculating.
  ///
  /// In en, this message translates to:
  /// **'Calculating...'**
  String get leavePreviewCalculating;

  /// No description provided for @leavePreviewSummary.
  ///
  /// In en, this message translates to:
  /// **'Summary'**
  String get leavePreviewSummary;

  /// No description provided for @leavePreviewDaysOfLeave.
  ///
  /// In en, this message translates to:
  /// **'{days} of leave'**
  String leavePreviewDaysOfLeave(String days);

  /// No description provided for @leavePreviewNotCounted.
  ///
  /// In en, this message translates to:
  /// **'Weekends and public holidays are not counted.'**
  String get leavePreviewNotCounted;

  /// No description provided for @leavePreviewMore.
  ///
  /// In en, this message translates to:
  /// **'+{n} more'**
  String leavePreviewMore(int n);

  /// No description provided for @leavePreviewUnlimited.
  ///
  /// In en, this message translates to:
  /// **'Not limited by a yearly balance.'**
  String get leavePreviewUnlimited;

  /// No description provided for @leavePreviewNotEnough.
  ///
  /// In en, this message translates to:
  /// **'Not enough balance: {available} available, this needs {needed}.'**
  String leavePreviewNotEnough(String available, String needed);

  /// No description provided for @leavePreviewLeftAfter.
  ///
  /// In en, this message translates to:
  /// **'{after} left after this request ({available} available now).'**
  String leavePreviewLeftAfter(String after, String available);

  /// No description provided for @leaveRequestButton.
  ///
  /// In en, this message translates to:
  /// **'Request leave'**
  String get leaveRequestButton;

  /// No description provided for @leaveEmptyTitle.
  ///
  /// In en, this message translates to:
  /// **'No leave requests yet'**
  String get leaveEmptyTitle;

  /// No description provided for @leaveEmptyMessage.
  ///
  /// In en, this message translates to:
  /// **'Plan time off: pick a type and dates, and see your balance before you submit.'**
  String get leaveEmptyMessage;

  /// No description provided for @leaveEmptyFiltered.
  ///
  /// In en, this message translates to:
  /// **'{status, select, draft{No draft requests} pending{No pending requests} approved{No approved requests} rejected{No rejected requests} cancelled{No cancelled requests} other{No requests}}'**
  String leaveEmptyFiltered(String status);

  /// No description provided for @leaveFilterAll.
  ///
  /// In en, this message translates to:
  /// **'All'**
  String get leaveFilterAll;

  /// No description provided for @leaveFilterPending.
  ///
  /// In en, this message translates to:
  /// **'Pending'**
  String get leaveFilterPending;

  /// No description provided for @leaveFilterApproved.
  ///
  /// In en, this message translates to:
  /// **'Approved'**
  String get leaveFilterApproved;

  /// No description provided for @leaveFilterDrafts.
  ///
  /// In en, this message translates to:
  /// **'Drafts'**
  String get leaveFilterDrafts;

  /// No description provided for @leaveFilterRejected.
  ///
  /// In en, this message translates to:
  /// **'Rejected'**
  String get leaveFilterRejected;

  /// No description provided for @leaveFilterCancelled.
  ///
  /// In en, this message translates to:
  /// **'Cancelled'**
  String get leaveFilterCancelled;

  /// No description provided for @leaveFilterSemantics.
  ///
  /// In en, this message translates to:
  /// **'Filter: {label}'**
  String leaveFilterSemantics(String label);

  /// No description provided for @leaveDetailTitle.
  ///
  /// In en, this message translates to:
  /// **'Leave request'**
  String get leaveDetailTitle;

  /// No description provided for @leaveConfirmDiscardTitle.
  ///
  /// In en, this message translates to:
  /// **'Discard this draft?'**
  String get leaveConfirmDiscardTitle;

  /// No description provided for @leaveConfirmCancelTitle.
  ///
  /// In en, this message translates to:
  /// **'Cancel this leave?'**
  String get leaveConfirmCancelTitle;

  /// No description provided for @leaveConfirmCancelApprovedMessage.
  ///
  /// In en, this message translates to:
  /// **'Your approved leave will be cancelled and the days returned to your balance.'**
  String get leaveConfirmCancelApprovedMessage;

  /// No description provided for @leaveConfirmWithdrawMessage.
  ///
  /// In en, this message translates to:
  /// **'The request will be withdrawn. You can submit a new one later.'**
  String get leaveConfirmWithdrawMessage;

  /// No description provided for @leaveConfirmDiscard.
  ///
  /// In en, this message translates to:
  /// **'Discard'**
  String get leaveConfirmDiscard;

  /// No description provided for @leaveConfirmCancelLeave.
  ///
  /// In en, this message translates to:
  /// **'Cancel leave'**
  String get leaveConfirmCancelLeave;

  /// No description provided for @leaveKeepIt.
  ///
  /// In en, this message translates to:
  /// **'Keep it'**
  String get leaveKeepIt;

  /// No description provided for @leaveDraftDiscarded.
  ///
  /// In en, this message translates to:
  /// **'Draft discarded'**
  String get leaveDraftDiscarded;

  /// No description provided for @leaveCancelledSnack.
  ///
  /// In en, this message translates to:
  /// **'Leave cancelled'**
  String get leaveCancelledSnack;

  /// No description provided for @leaveUnpaid.
  ///
  /// In en, this message translates to:
  /// **'Unpaid'**
  String get leaveUnpaid;

  /// No description provided for @leaveDetailDates.
  ///
  /// In en, this message translates to:
  /// **'Dates'**
  String get leaveDetailDates;

  /// No description provided for @leaveDetailDuration.
  ///
  /// In en, this message translates to:
  /// **'Duration'**
  String get leaveDetailDuration;

  /// No description provided for @leaveDetailType.
  ///
  /// In en, this message translates to:
  /// **'Type'**
  String get leaveDetailType;

  /// No description provided for @leaveDetailWorkingDays.
  ///
  /// In en, this message translates to:
  /// **'Working days'**
  String get leaveDetailWorkingDays;

  /// No description provided for @leaveDetailReason.
  ///
  /// In en, this message translates to:
  /// **'Reason'**
  String get leaveDetailReason;

  /// No description provided for @leaveDetailRejectionReason.
  ///
  /// In en, this message translates to:
  /// **'Rejection reason'**
  String get leaveDetailRejectionReason;

  /// No description provided for @leaveDetailStatus.
  ///
  /// In en, this message translates to:
  /// **'Status'**
  String get leaveDetailStatus;

  /// No description provided for @leaveSubmitRequest.
  ///
  /// In en, this message translates to:
  /// **'Submit request'**
  String get leaveSubmitRequest;

  /// No description provided for @leaveSubmittedSnack.
  ///
  /// In en, this message translates to:
  /// **'Leave request submitted'**
  String get leaveSubmittedSnack;

  /// No description provided for @leaveEditDraft.
  ///
  /// In en, this message translates to:
  /// **'Edit draft'**
  String get leaveEditDraft;

  /// No description provided for @leaveDiscardDraft.
  ///
  /// In en, this message translates to:
  /// **'Discard draft'**
  String get leaveDiscardDraft;

  /// No description provided for @leaveCancelApproved.
  ///
  /// In en, this message translates to:
  /// **'Cancel approved leave'**
  String get leaveCancelApproved;

  /// No description provided for @leaveCancelRequest.
  ///
  /// In en, this message translates to:
  /// **'Cancel request'**
  String get leaveCancelRequest;

  /// No description provided for @leaveStepCreated.
  ///
  /// In en, this message translates to:
  /// **'Created'**
  String get leaveStepCreated;

  /// No description provided for @leaveStepSubmitted.
  ///
  /// In en, this message translates to:
  /// **'Submitted for approval'**
  String get leaveStepSubmitted;

  /// No description provided for @leaveStepDraft.
  ///
  /// In en, this message translates to:
  /// **'Draft, not submitted yet'**
  String get leaveStepDraft;

  /// No description provided for @leaveStepWaiting.
  ///
  /// In en, this message translates to:
  /// **'Waiting for your manager'**
  String get leaveStepWaiting;

  /// No description provided for @leaveStepApproved.
  ///
  /// In en, this message translates to:
  /// **'Approved'**
  String get leaveStepApproved;

  /// No description provided for @leaveStepApprovedBy.
  ///
  /// In en, this message translates to:
  /// **'Approved by {name}'**
  String leaveStepApprovedBy(String name);

  /// No description provided for @leaveStepRejected.
  ///
  /// In en, this message translates to:
  /// **'Rejected'**
  String get leaveStepRejected;

  /// No description provided for @leaveStepRejectedBy.
  ///
  /// In en, this message translates to:
  /// **'Rejected by {name}'**
  String leaveStepRejectedBy(String name);

  /// No description provided for @leaveStepCancelled.
  ///
  /// In en, this message translates to:
  /// **'Cancelled'**
  String get leaveStepCancelled;

  /// No description provided for @leaveFormTitle.
  ///
  /// In en, this message translates to:
  /// **'Request leave'**
  String get leaveFormTitle;

  /// No description provided for @leaveFormEditTitle.
  ///
  /// In en, this message translates to:
  /// **'Edit leave draft'**
  String get leaveFormEditTitle;

  /// No description provided for @leaveFormType.
  ///
  /// In en, this message translates to:
  /// **'Leave type'**
  String get leaveFormType;

  /// No description provided for @leaveFormTypeLoading.
  ///
  /// In en, this message translates to:
  /// **'Loading leave types...'**
  String get leaveFormTypeLoading;

  /// No description provided for @leaveFormTypeHint.
  ///
  /// In en, this message translates to:
  /// **'Choose a type'**
  String get leaveFormTypeHint;

  /// No description provided for @leaveTypeNotAvailable.
  ///
  /// In en, this message translates to:
  /// **'Not available for you yet'**
  String get leaveTypeNotAvailable;

  /// No description provided for @leaveTypeUnpaidNoLimit.
  ///
  /// In en, this message translates to:
  /// **'Unpaid · no yearly limit'**
  String get leaveTypeUnpaidNoLimit;

  /// No description provided for @leaveTypeAvailableOf.
  ///
  /// In en, this message translates to:
  /// **'{available} available of {total}'**
  String leaveTypeAvailableOf(String available, String total);

  /// No description provided for @leaveFormFirstDayHelp.
  ///
  /// In en, this message translates to:
  /// **'First day of leave'**
  String get leaveFormFirstDayHelp;

  /// No description provided for @leaveFormLastDayHelp.
  ///
  /// In en, this message translates to:
  /// **'Last day of leave'**
  String get leaveFormLastDayHelp;

  /// No description provided for @leaveValChooseType.
  ///
  /// In en, this message translates to:
  /// **'Choose a leave type.'**
  String get leaveValChooseType;

  /// No description provided for @leaveValChooseFirstDay.
  ///
  /// In en, this message translates to:
  /// **'Choose the first day.'**
  String get leaveValChooseFirstDay;

  /// No description provided for @leaveValChooseLastDay.
  ///
  /// In en, this message translates to:
  /// **'Choose the last day.'**
  String get leaveValChooseLastDay;

  /// No description provided for @leaveValReasonRequired.
  ///
  /// In en, this message translates to:
  /// **'A reason is required for {type}.'**
  String leaveValReasonRequired(String type);

  /// No description provided for @leaveDraftSaved.
  ///
  /// In en, this message translates to:
  /// **'Draft saved'**
  String get leaveDraftSaved;

  /// No description provided for @leaveSaveDraft.
  ///
  /// In en, this message translates to:
  /// **'Save draft'**
  String get leaveSaveDraft;

  /// No description provided for @leaveSaveAsDraft.
  ///
  /// In en, this message translates to:
  /// **'Save as draft'**
  String get leaveSaveAsDraft;

  /// No description provided for @leaveSubmit.
  ///
  /// In en, this message translates to:
  /// **'Submit'**
  String get leaveSubmit;

  /// No description provided for @leaveFormFrom.
  ///
  /// In en, this message translates to:
  /// **'From'**
  String get leaveFormFrom;

  /// No description provided for @leaveFormTo.
  ///
  /// In en, this message translates to:
  /// **'To'**
  String get leaveFormTo;

  /// No description provided for @leaveFormFirstDay.
  ///
  /// In en, this message translates to:
  /// **'First day'**
  String get leaveFormFirstDay;

  /// No description provided for @leaveFormLastDay.
  ///
  /// In en, this message translates to:
  /// **'Last day'**
  String get leaveFormLastDay;

  /// No description provided for @leaveFormLength.
  ///
  /// In en, this message translates to:
  /// **'Length'**
  String get leaveFormLength;

  /// No description provided for @leaveFormFullDay.
  ///
  /// In en, this message translates to:
  /// **'Full day'**
  String get leaveFormFullDay;

  /// No description provided for @leaveFormMorning.
  ///
  /// In en, this message translates to:
  /// **'Morning'**
  String get leaveFormMorning;

  /// No description provided for @leaveFormAfternoon.
  ///
  /// In en, this message translates to:
  /// **'Afternoon'**
  String get leaveFormAfternoon;

  /// No description provided for @leaveFormReasonRequired.
  ///
  /// In en, this message translates to:
  /// **'Reason (required)'**
  String get leaveFormReasonRequired;

  /// No description provided for @leaveFormReasonOptional.
  ///
  /// In en, this message translates to:
  /// **'Reason (optional)'**
  String get leaveFormReasonOptional;

  /// No description provided for @leaveFormReasonHint.
  ///
  /// In en, this message translates to:
  /// **'e.g. Family event in Medan'**
  String get leaveFormReasonHint;

  /// No description provided for @notifTitle.
  ///
  /// In en, this message translates to:
  /// **'Notifications'**
  String get notifTitle;

  /// No description provided for @notifMarkAllRead.
  ///
  /// In en, this message translates to:
  /// **'Mark all read'**
  String get notifMarkAllRead;

  /// No description provided for @notifMarkAllFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not mark notifications as read. Nothing was changed.'**
  String get notifMarkAllFailed;

  /// No description provided for @notifEmptyTitle.
  ///
  /// In en, this message translates to:
  /// **'You\'\'re all caught up'**
  String get notifEmptyTitle;

  /// No description provided for @notifEmptyMessage.
  ///
  /// In en, this message translates to:
  /// **'Updates about your leave, corrections and overtime appear here.'**
  String get notifEmptyMessage;

  /// No description provided for @notifSemantics.
  ///
  /// In en, this message translates to:
  /// **'{title}. {body}. {age}'**
  String notifSemantics(String title, String body, String age);

  /// No description provided for @notifSemanticsUnread.
  ///
  /// In en, this message translates to:
  /// **'Unread. {title}. {body}. {age}'**
  String notifSemanticsUnread(String title, String body, String age);

  /// No description provided for @notifTypeLeavePendingReview.
  ///
  /// In en, this message translates to:
  /// **'Leave request to review'**
  String get notifTypeLeavePendingReview;

  /// No description provided for @notifTypeLeaveApproved.
  ///
  /// In en, this message translates to:
  /// **'Leave approved'**
  String get notifTypeLeaveApproved;

  /// No description provided for @notifTypeLeaveRejected.
  ///
  /// In en, this message translates to:
  /// **'Leave rejected'**
  String get notifTypeLeaveRejected;

  /// No description provided for @notifTypeCorrectionApproved.
  ///
  /// In en, this message translates to:
  /// **'Attendance correction approved'**
  String get notifTypeCorrectionApproved;

  /// No description provided for @notifTypeCorrectionRejected.
  ///
  /// In en, this message translates to:
  /// **'Attendance correction rejected'**
  String get notifTypeCorrectionRejected;

  /// No description provided for @notifTypeOvertimeApproved.
  ///
  /// In en, this message translates to:
  /// **'Overtime approved'**
  String get notifTypeOvertimeApproved;

  /// No description provided for @notifTypeOvertimeRejected.
  ///
  /// In en, this message translates to:
  /// **'Overtime rejected'**
  String get notifTypeOvertimeRejected;

  /// No description provided for @notifTypePayslipAvailable.
  ///
  /// In en, this message translates to:
  /// **'Payslip available'**
  String get notifTypePayslipAvailable;

  /// No description provided for @notifTypeDocumentExpiring.
  ///
  /// In en, this message translates to:
  /// **'Document expiring'**
  String get notifTypeDocumentExpiring;

  /// No description provided for @notifTypeDocumentExpired.
  ///
  /// In en, this message translates to:
  /// **'Document expired'**
  String get notifTypeDocumentExpired;

  /// No description provided for @payslipTitle.
  ///
  /// In en, this message translates to:
  /// **'Payslips'**
  String get payslipTitle;

  /// No description provided for @payslipDetailTitle.
  ///
  /// In en, this message translates to:
  /// **'Payslip'**
  String get payslipDetailTitle;

  /// No description provided for @payslipSelectPrompt.
  ///
  /// In en, this message translates to:
  /// **'Select a payslip'**
  String get payslipSelectPrompt;

  /// No description provided for @payslipYearAll.
  ///
  /// In en, this message translates to:
  /// **'All'**
  String get payslipYearAll;

  /// No description provided for @payslipYearAllSemantics.
  ///
  /// In en, this message translates to:
  /// **'All years'**
  String get payslipYearAllSemantics;

  /// No description provided for @payslipYearSemantics.
  ///
  /// In en, this message translates to:
  /// **'Year {year}'**
  String payslipYearSemantics(String year);

  /// No description provided for @payslipEmptyTitle.
  ///
  /// In en, this message translates to:
  /// **'No payslips yet'**
  String get payslipEmptyTitle;

  /// No description provided for @payslipEmptyMessage.
  ///
  /// In en, this message translates to:
  /// **'Your payslips appear here once payroll for a period is released.'**
  String get payslipEmptyMessage;

  /// No description provided for @payslipStatusPaid.
  ///
  /// In en, this message translates to:
  /// **'Paid'**
  String get payslipStatusPaid;

  /// No description provided for @payslipStatusReleased.
  ///
  /// In en, this message translates to:
  /// **'Released'**
  String get payslipStatusReleased;

  /// No description provided for @payslipCardSemanticsPaid.
  ///
  /// In en, this message translates to:
  /// **'{period}, net pay {amount}, paid'**
  String payslipCardSemanticsPaid(String period, String amount);

  /// No description provided for @payslipCardSemanticsReleased.
  ///
  /// In en, this message translates to:
  /// **'{period}, net pay {amount}, released'**
  String payslipCardSemanticsReleased(String period, String amount);

  /// No description provided for @payslipCaption.
  ///
  /// In en, this message translates to:
  /// **'PAYSLIP'**
  String get payslipCaption;

  /// No description provided for @payslipPeriodRange.
  ///
  /// In en, this message translates to:
  /// **'Period {start} - {end}'**
  String payslipPeriodRange(String start, String end);

  /// No description provided for @payslipPeriodRangePaid.
  ///
  /// In en, this message translates to:
  /// **'Period {start} - {end} · Paid {payDate}'**
  String payslipPeriodRangePaid(String start, String end, String payDate);

  /// No description provided for @payslipInputsPresent.
  ///
  /// In en, this message translates to:
  /// **'{present} of {working} days present'**
  String payslipInputsPresent(String present, String working);

  /// No description provided for @payslipInputsOvertime.
  ///
  /// In en, this message translates to:
  /// **'Overtime {duration}'**
  String payslipInputsOvertime(String duration);

  /// No description provided for @payslipInputsUnpaidLeave.
  ///
  /// In en, this message translates to:
  /// **'{days} unpaid leave days'**
  String payslipInputsUnpaidLeave(String days);

  /// No description provided for @payslipEarnings.
  ///
  /// In en, this message translates to:
  /// **'Earnings'**
  String get payslipEarnings;

  /// No description provided for @payslipDeductions.
  ///
  /// In en, this message translates to:
  /// **'Deductions'**
  String get payslipDeductions;

  /// No description provided for @payslipGrossPay.
  ///
  /// In en, this message translates to:
  /// **'Gross pay'**
  String get payslipGrossPay;

  /// No description provided for @payslipTotalDeductions.
  ///
  /// In en, this message translates to:
  /// **'Total deductions'**
  String get payslipTotalDeductions;

  /// No description provided for @payslipNoDeductions.
  ///
  /// In en, this message translates to:
  /// **'No deductions this period.'**
  String get payslipNoDeductions;

  /// No description provided for @payslipNetPay.
  ///
  /// In en, this message translates to:
  /// **'Net pay'**
  String get payslipNetPay;

  /// No description provided for @payslipNetPaySemantics.
  ///
  /// In en, this message translates to:
  /// **'Net pay {amount}'**
  String payslipNetPaySemantics(String amount);

  /// No description provided for @payslipAdjustment.
  ///
  /// In en, this message translates to:
  /// **'Adjustment: {reason}'**
  String payslipAdjustment(String reason);

  /// No description provided for @payslipDownloadPdf.
  ///
  /// In en, this message translates to:
  /// **'Download PDF'**
  String get payslipDownloadPdf;

  /// No description provided for @requestsTitle.
  ///
  /// In en, this message translates to:
  /// **'Requests'**
  String get requestsTitle;

  /// No description provided for @requestsTabLeave.
  ///
  /// In en, this message translates to:
  /// **'Leave'**
  String get requestsTabLeave;

  /// No description provided for @requestsTabCorrections.
  ///
  /// In en, this message translates to:
  /// **'Corrections'**
  String get requestsTabCorrections;

  /// No description provided for @requestsTabOvertime.
  ///
  /// In en, this message translates to:
  /// **'Overtime'**
  String get requestsTabOvertime;

  /// No description provided for @requestsSelectPrompt.
  ///
  /// In en, this message translates to:
  /// **'Select a request'**
  String get requestsSelectPrompt;

  /// No description provided for @requestsStatusPending.
  ///
  /// In en, this message translates to:
  /// **'Pending'**
  String get requestsStatusPending;

  /// No description provided for @requestsStatusApproved.
  ///
  /// In en, this message translates to:
  /// **'Approved'**
  String get requestsStatusApproved;

  /// No description provided for @requestsStatusRejected.
  ///
  /// In en, this message translates to:
  /// **'Rejected'**
  String get requestsStatusRejected;

  /// No description provided for @requestsStatusCancelled.
  ///
  /// In en, this message translates to:
  /// **'Cancelled'**
  String get requestsStatusCancelled;

  /// No description provided for @requestsNewCorrection.
  ///
  /// In en, this message translates to:
  /// **'Request correction'**
  String get requestsNewCorrection;

  /// No description provided for @requestsCorrectionsEmptyTitle.
  ///
  /// In en, this message translates to:
  /// **'No correction requests'**
  String get requestsCorrectionsEmptyTitle;

  /// No description provided for @requestsCorrectionsEmptyMessage.
  ///
  /// In en, this message translates to:
  /// **'Forgot to check out or in? Request a correction and your manager will review it.'**
  String get requestsCorrectionsEmptyMessage;

  /// No description provided for @requestsCorrectionIn.
  ///
  /// In en, this message translates to:
  /// **'In {time}'**
  String requestsCorrectionIn(String time);

  /// No description provided for @requestsCorrectionOut.
  ///
  /// In en, this message translates to:
  /// **'Out {time}'**
  String requestsCorrectionOut(String time);

  /// No description provided for @requestsNewOvertime.
  ///
  /// In en, this message translates to:
  /// **'Request overtime'**
  String get requestsNewOvertime;

  /// No description provided for @requestsOvertimeEmptyTitle.
  ///
  /// In en, this message translates to:
  /// **'No overtime requests'**
  String get requestsOvertimeEmptyTitle;

  /// No description provided for @requestsOvertimeEmptyMessage.
  ///
  /// In en, this message translates to:
  /// **'Planning to work extra hours? Submit a request first.'**
  String get requestsOvertimeEmptyMessage;

  /// No description provided for @requestsDetailWorkDate.
  ///
  /// In en, this message translates to:
  /// **'Work date'**
  String get requestsDetailWorkDate;

  /// No description provided for @requestsDetailCheckIn.
  ///
  /// In en, this message translates to:
  /// **'Check in'**
  String get requestsDetailCheckIn;

  /// No description provided for @requestsDetailCheckOut.
  ///
  /// In en, this message translates to:
  /// **'Check out'**
  String get requestsDetailCheckOut;

  /// No description provided for @requestsDetailSubmitted.
  ///
  /// In en, this message translates to:
  /// **'Submitted'**
  String get requestsDetailSubmitted;

  /// No description provided for @requestsDetailTime.
  ///
  /// In en, this message translates to:
  /// **'Time'**
  String get requestsDetailTime;

  /// No description provided for @requestsDetailRequested.
  ///
  /// In en, this message translates to:
  /// **'Requested'**
  String get requestsDetailRequested;

  /// No description provided for @requestsDetailApproved.
  ///
  /// In en, this message translates to:
  /// **'Approved'**
  String get requestsDetailApproved;

  /// No description provided for @requestsCorrectionDetailTitle.
  ///
  /// In en, this message translates to:
  /// **'Correction request'**
  String get requestsCorrectionDetailTitle;

  /// No description provided for @requestsOvertimeDetailTitle.
  ///
  /// In en, this message translates to:
  /// **'Overtime request'**
  String get requestsOvertimeDetailTitle;

  /// No description provided for @requestsCorrectionCancelTitle.
  ///
  /// In en, this message translates to:
  /// **'Cancel this request?'**
  String get requestsCorrectionCancelTitle;

  /// No description provided for @requestsOvertimeCancelTitle.
  ///
  /// In en, this message translates to:
  /// **'Cancel this overtime request?'**
  String get requestsOvertimeCancelTitle;

  /// No description provided for @requestsCancelMessage.
  ///
  /// In en, this message translates to:
  /// **'This cannot be undone. You can submit a new request later.'**
  String get requestsCancelMessage;

  /// No description provided for @requestsCancelRequest.
  ///
  /// In en, this message translates to:
  /// **'Cancel request'**
  String get requestsCancelRequest;

  /// No description provided for @requestsKeepIt.
  ///
  /// In en, this message translates to:
  /// **'Keep it'**
  String get requestsKeepIt;

  /// No description provided for @requestsCancelledSnack.
  ///
  /// In en, this message translates to:
  /// **'Request cancelled'**
  String get requestsCancelledSnack;

  /// No description provided for @requestsReason.
  ///
  /// In en, this message translates to:
  /// **'Reason'**
  String get requestsReason;

  /// No description provided for @requestsReviewerNote.
  ///
  /// In en, this message translates to:
  /// **'Reviewer note'**
  String get requestsReviewerNote;

  /// No description provided for @requestsErrCorrectionAlreadyPending.
  ///
  /// In en, this message translates to:
  /// **'There is already a pending correction for this date.'**
  String get requestsErrCorrectionAlreadyPending;

  /// No description provided for @requestsErrCorrectionWindowExpired.
  ///
  /// In en, this message translates to:
  /// **'Corrections can only be requested for the last 30 days.'**
  String get requestsErrCorrectionWindowExpired;

  /// No description provided for @requestsErrInvalidWorkDate.
  ///
  /// In en, this message translates to:
  /// **'That work date is not valid.'**
  String get requestsErrInvalidWorkDate;

  /// No description provided for @requestsErrCheckInRequired.
  ///
  /// In en, this message translates to:
  /// **'A check-in time is required for this request.'**
  String get requestsErrCheckInRequired;

  /// No description provided for @requestsErrOvertimeTooLong.
  ///
  /// In en, this message translates to:
  /// **'That overtime is longer than the allowed limit.'**
  String get requestsErrOvertimeTooLong;

  /// No description provided for @requestsErrOvertimeOverlap.
  ///
  /// In en, this message translates to:
  /// **'This overlaps another overtime request.'**
  String get requestsErrOvertimeOverlap;

  /// No description provided for @requestsErrNotPending.
  ///
  /// In en, this message translates to:
  /// **'This request is no longer pending, so it cannot be changed.'**
  String get requestsErrNotPending;

  /// No description provided for @requestsErrInvalidEventTime.
  ///
  /// In en, this message translates to:
  /// **'The times you entered are not possible.'**
  String get requestsErrInvalidEventTime;

  /// No description provided for @requestsCorrectionDateHelp.
  ///
  /// In en, this message translates to:
  /// **'Work date (last 30 days)'**
  String get requestsCorrectionDateHelp;

  /// No description provided for @requestsValChooseWorkDate.
  ///
  /// In en, this message translates to:
  /// **'Choose the work date.'**
  String get requestsValChooseWorkDate;

  /// No description provided for @requestsValTimes.
  ///
  /// In en, this message translates to:
  /// **'Add a check-in time, a check-out time, or both.'**
  String get requestsValTimes;

  /// No description provided for @requestsValReasonWhatHappened.
  ///
  /// In en, this message translates to:
  /// **'Explain what happened (at least 5 characters).'**
  String get requestsValReasonWhatHappened;

  /// No description provided for @requestsCorrectionSubmitted.
  ///
  /// In en, this message translates to:
  /// **'Correction request submitted'**
  String get requestsCorrectionSubmitted;

  /// No description provided for @requestsSubmitRequest.
  ///
  /// In en, this message translates to:
  /// **'Submit request'**
  String get requestsSubmitRequest;

  /// No description provided for @requestsCorrectionIntro.
  ///
  /// In en, this message translates to:
  /// **'Forgot to check in or out? Tell us what the times should be. Your manager reviews the request before your record changes.'**
  String get requestsCorrectionIntro;

  /// No description provided for @requestsChooseDate.
  ///
  /// In en, this message translates to:
  /// **'Choose a date'**
  String get requestsChooseDate;

  /// No description provided for @requestsCheckInTime.
  ///
  /// In en, this message translates to:
  /// **'Check-in time'**
  String get requestsCheckInTime;

  /// No description provided for @requestsCheckOutTime.
  ///
  /// In en, this message translates to:
  /// **'Check-out time'**
  String get requestsCheckOutTime;

  /// No description provided for @requestsOptional.
  ///
  /// In en, this message translates to:
  /// **'Optional'**
  String get requestsOptional;

  /// No description provided for @requestsCorrectionReasonHint.
  ///
  /// In en, this message translates to:
  /// **'e.g. I forgot to check out after the meeting'**
  String get requestsCorrectionReasonHint;

  /// No description provided for @requestsOvertimeDateHelp.
  ///
  /// In en, this message translates to:
  /// **'Overtime date'**
  String get requestsOvertimeDateHelp;

  /// No description provided for @requestsValChooseDate.
  ///
  /// In en, this message translates to:
  /// **'Choose the date.'**
  String get requestsValChooseDate;

  /// No description provided for @requestsValChooseStart.
  ///
  /// In en, this message translates to:
  /// **'Choose the start time.'**
  String get requestsValChooseStart;

  /// No description provided for @requestsValChooseEnd.
  ///
  /// In en, this message translates to:
  /// **'Choose the end time.'**
  String get requestsValChooseEnd;

  /// No description provided for @requestsValEndAfterStart.
  ///
  /// In en, this message translates to:
  /// **'The end time must be after the start time.'**
  String get requestsValEndAfterStart;

  /// No description provided for @requestsValReasonWhy.
  ///
  /// In en, this message translates to:
  /// **'Explain why (at least 5 characters).'**
  String get requestsValReasonWhy;

  /// No description provided for @requestsOvertimeSubmitted.
  ///
  /// In en, this message translates to:
  /// **'Overtime request submitted'**
  String get requestsOvertimeSubmitted;

  /// No description provided for @requestsOvertimeIntro.
  ///
  /// In en, this message translates to:
  /// **'Request approval before you work extra hours. Approved overtime is recorded for your manager and HR.'**
  String get requestsOvertimeIntro;

  /// No description provided for @requestsDate.
  ///
  /// In en, this message translates to:
  /// **'Date'**
  String get requestsDate;

  /// No description provided for @requestsStartTime.
  ///
  /// In en, this message translates to:
  /// **'Start time'**
  String get requestsStartTime;

  /// No description provided for @requestsEndTime.
  ///
  /// In en, this message translates to:
  /// **'End time'**
  String get requestsEndTime;

  /// No description provided for @requestsStart.
  ///
  /// In en, this message translates to:
  /// **'Start'**
  String get requestsStart;

  /// No description provided for @requestsEnd.
  ///
  /// In en, this message translates to:
  /// **'End'**
  String get requestsEnd;

  /// No description provided for @requestsOvertimeReasonHint.
  ///
  /// In en, this message translates to:
  /// **'What will you work on?'**
  String get requestsOvertimeReasonHint;

  /// No description provided for @requestsTimeRange.
  ///
  /// In en, this message translates to:
  /// **'{start} - {end}'**
  String requestsTimeRange(String start, String end);

  /// No description provided for @requestsOvertimeSubtitle.
  ///
  /// In en, this message translates to:
  /// **'{start} - {end} · {duration}'**
  String requestsOvertimeSubtitle(String start, String end, String duration);

  /// No description provided for @syncSyncing.
  ///
  /// In en, this message translates to:
  /// **'Syncing...'**
  String get syncSyncing;

  /// No description provided for @syncFailedCount.
  ///
  /// In en, this message translates to:
  /// **'{count} sync failed'**
  String syncFailedCount(int count);

  /// No description provided for @syncPendingCount.
  ///
  /// In en, this message translates to:
  /// **'{count} pending sync'**
  String syncPendingCount(int count);

  /// No description provided for @syncAllSynced.
  ///
  /// In en, this message translates to:
  /// **'All synced'**
  String get syncAllSynced;

  /// No description provided for @syncTitle.
  ///
  /// In en, this message translates to:
  /// **'Sync status'**
  String get syncTitle;

  /// No description provided for @syncNow.
  ///
  /// In en, this message translates to:
  /// **'Sync now'**
  String get syncNow;

  /// No description provided for @syncEverythingSynced.
  ///
  /// In en, this message translates to:
  /// **'Everything is synced'**
  String get syncEverythingSynced;

  /// No description provided for @syncEverythingSyncedMsg.
  ///
  /// In en, this message translates to:
  /// **'Attendance you record while offline appears here until it reaches the server.'**
  String get syncEverythingSyncedMsg;

  /// No description provided for @syncOnline.
  ///
  /// In en, this message translates to:
  /// **'Online'**
  String get syncOnline;

  /// No description provided for @syncOffline.
  ///
  /// In en, this message translates to:
  /// **'Offline'**
  String get syncOffline;

  /// No description provided for @syncPendingFailedSummary.
  ///
  /// In en, this message translates to:
  /// **'{pending} pending · {failed} failed'**
  String syncPendingFailedSummary(int pending, int failed);

  /// No description provided for @syncStateSaved.
  ///
  /// In en, this message translates to:
  /// **'Saved'**
  String get syncStateSaved;

  /// No description provided for @syncStateSynced.
  ///
  /// In en, this message translates to:
  /// **'Synced'**
  String get syncStateSynced;

  /// No description provided for @syncAttempts.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 attempt} other{{count} attempts}}'**
  String syncAttempts(int count);

  /// No description provided for @syncDiscard.
  ///
  /// In en, this message translates to:
  /// **'Discard'**
  String get syncDiscard;

  /// No description provided for @syncDiscardTitle.
  ///
  /// In en, this message translates to:
  /// **'Discard this action?'**
  String get syncDiscardTitle;

  /// No description provided for @syncDiscardMessage.
  ///
  /// In en, this message translates to:
  /// **'It will not be sent to the server. Use a correction request if your attendance needs fixing.'**
  String get syncDiscardMessage;
}

class _AppLocalizationsDelegate
    extends LocalizationsDelegate<AppLocalizations> {
  const _AppLocalizationsDelegate();

  @override
  Future<AppLocalizations> load(Locale locale) {
    return SynchronousFuture<AppLocalizations>(lookupAppLocalizations(locale));
  }

  @override
  bool isSupported(Locale locale) =>
      <String>['en', 'id'].contains(locale.languageCode);

  @override
  bool shouldReload(_AppLocalizationsDelegate old) => false;
}

AppLocalizations lookupAppLocalizations(Locale locale) {
  // Lookup logic when only language code is specified.
  switch (locale.languageCode) {
    case 'en':
      return AppLocalizationsEn();
    case 'id':
      return AppLocalizationsId();
  }

  throw FlutterError(
    'AppLocalizations.delegate failed to load unsupported locale "$locale". This is likely '
    'an issue with the localizations generation tool. Please file an issue '
    'on GitHub with a reproducible sample app and the gen-l10n configuration '
    'that was used.',
  );
}
