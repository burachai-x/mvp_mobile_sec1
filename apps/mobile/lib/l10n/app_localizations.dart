import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/intl.dart' as intl;

import 'app_localizations_en.dart';
import 'app_localizations_th.dart';

// ignore_for_file: type=lint

/// Callers can lookup localized strings with an instance of AppLocalizations
/// returned by `AppLocalizations.of(context)`.
///
/// Applications need to include `AppLocalizations.delegate()` in their app's
/// `localizationDelegates` list, and the locales they support in the app's
/// `supportedLocales` list. For example:
///
/// ```dart
/// import 'l10n/app_localizations.dart';
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

  static AppLocalizations? of(BuildContext context) {
    return Localizations.of<AppLocalizations>(context, AppLocalizations);
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
    Locale('th'),
  ];

  /// No description provided for @signalUsbDebugging.
  ///
  /// In en, this message translates to:
  /// **'USB debugging is on'**
  String get signalUsbDebugging;

  /// No description provided for @signalWirelessDebugging.
  ///
  /// In en, this message translates to:
  /// **'Wireless debugging is on'**
  String get signalWirelessDebugging;

  /// No description provided for @signalDeveloperOptions.
  ///
  /// In en, this message translates to:
  /// **'Developer options are on'**
  String get signalDeveloperOptions;

  /// No description provided for @signalDebuggerAttached.
  ///
  /// In en, this message translates to:
  /// **'A debugger is attached'**
  String get signalDebuggerAttached;

  /// No description provided for @signalRooted.
  ///
  /// In en, this message translates to:
  /// **'This device is rooted'**
  String get signalRooted;

  /// No description provided for @signalSuBinaryFound.
  ///
  /// In en, this message translates to:
  /// **'An su binary was found on this device'**
  String get signalSuBinaryFound;

  /// No description provided for @signalTestKeys.
  ///
  /// In en, this message translates to:
  /// **'The system was not signed by the manufacturer'**
  String get signalTestKeys;

  /// No description provided for @signalHookFrameworkDetected.
  ///
  /// In en, this message translates to:
  /// **'A framework that rewrites app behaviour was found'**
  String get signalHookFrameworkDetected;

  /// No description provided for @signalEmulator.
  ///
  /// In en, this message translates to:
  /// **'Running on an emulator'**
  String get signalEmulator;

  /// No description provided for @integrityFatalTitle.
  ///
  /// In en, this message translates to:
  /// **'Cannot continue'**
  String get integrityFatalTitle;

  /// No description provided for @integrityWarnTitle.
  ///
  /// In en, this message translates to:
  /// **'Risk detected'**
  String get integrityWarnTitle;

  /// No description provided for @integrityFatalBody.
  ///
  /// In en, this message translates to:
  /// **'This device is not safe enough to use'**
  String get integrityFatalBody;

  /// No description provided for @integrityWarnBody.
  ///
  /// In en, this message translates to:
  /// **'Turn these settings off before using the app'**
  String get integrityWarnBody;

  /// No description provided for @integrityCloseApp.
  ///
  /// In en, this message translates to:
  /// **'Close the app'**
  String get integrityCloseApp;

  /// No description provided for @integrityRecheck.
  ///
  /// In en, this message translates to:
  /// **'Check again'**
  String get integrityRecheck;

  /// No description provided for @integrityContinue.
  ///
  /// In en, this message translates to:
  /// **'Continue anyway'**
  String get integrityContinue;

  /// No description provided for @integrityContactStaffTitle.
  ///
  /// In en, this message translates to:
  /// **'Please contact staff'**
  String get integrityContactStaffTitle;

  /// No description provided for @integrityContactStaffBody.
  ///
  /// In en, this message translates to:
  /// **'so the device can be checked before you start'**
  String get integrityContactStaffBody;

  /// Numbered by hand, not built from a list widget: the lines have to break exactly where written or Thai wraps mid-word.
  ///
  /// In en, this message translates to:
  /// **'1.  Open Settings on the device\n2.  Choose Developer options\n3.  Turn off USB debugging and wireless debugging\n4.  Come back and tap Check again'**
  String get integritySteps;

  /// No description provided for @lockEnterPin.
  ///
  /// In en, this message translates to:
  /// **'Enter your PIN'**
  String get lockEnterPin;

  /// No description provided for @lockForgotPin.
  ///
  /// In en, this message translates to:
  /// **'If you have forgotten your PIN, contact staff'**
  String get lockForgotPin;

  /// No description provided for @enrollTitle.
  ///
  /// In en, this message translates to:
  /// **'Driver enrollment'**
  String get enrollTitle;

  /// No description provided for @enrolling.
  ///
  /// In en, this message translates to:
  /// **'Enrolling…'**
  String get enrolling;

  /// No description provided for @enrollScanPrompt.
  ///
  /// In en, this message translates to:
  /// **'Scan the activation QR that staff issued for you.'**
  String get enrollScanPrompt;

  /// No description provided for @enrollScanButton.
  ///
  /// In en, this message translates to:
  /// **'Scan QR'**
  String get enrollScanButton;

  /// No description provided for @scanActivationQr.
  ///
  /// In en, this message translates to:
  /// **'Scan activation QR'**
  String get scanActivationQr;

  /// No description provided for @deviceEnrolled.
  ///
  /// In en, this message translates to:
  /// **'This device is enrolled.'**
  String get deviceEnrolled;

  /// No description provided for @integrityFlagged.
  ///
  /// In en, this message translates to:
  /// **'Device checks flagged: {signals}'**
  String integrityFlagged(String signals);

  /// No description provided for @integrityReported.
  ///
  /// In en, this message translates to:
  /// **'Reported to staff. Enrollment is not blocked by this.'**
  String get integrityReported;

  /// No description provided for @biometricTurnOff.
  ///
  /// In en, this message translates to:
  /// **'Turn off fingerprint unlock'**
  String get biometricTurnOff;

  /// No description provided for @biometricUseNext.
  ///
  /// In en, this message translates to:
  /// **'Use fingerprint next time'**
  String get biometricUseNext;

  /// No description provided for @pinSetTitle.
  ///
  /// In en, this message translates to:
  /// **'Set a 6-digit PIN'**
  String get pinSetTitle;

  /// No description provided for @pinWeakHint.
  ///
  /// In en, this message translates to:
  /// **'Avoid 123456, 000000 or a repeated digit — the server rejects those.'**
  String get pinWeakHint;

  /// No description provided for @confirm.
  ///
  /// In en, this message translates to:
  /// **'Confirm'**
  String get confirm;

  /// No description provided for @errPinResetRequired.
  ///
  /// In en, this message translates to:
  /// **'Staff have reset your PIN. Please set a new one.'**
  String get errPinResetRequired;

  /// No description provided for @errPinAlreadySet.
  ///
  /// In en, this message translates to:
  /// **'This device already has a PIN.'**
  String get errPinAlreadySet;

  /// No description provided for @errPinInvalidAttemptsLeft.
  ///
  /// In en, this message translates to:
  /// **'Wrong PIN. {count} attempts left.'**
  String errPinInvalidAttemptsLeft(int count);

  /// No description provided for @errPinInvalid.
  ///
  /// In en, this message translates to:
  /// **'Wrong PIN.'**
  String get errPinInvalid;

  /// No description provided for @errActivationCodeUsed.
  ///
  /// In en, this message translates to:
  /// **'This code has already been used or has expired. Ask staff for a new one.'**
  String get errActivationCodeUsed;

  /// No description provided for @errDriverHasActiveDevice.
  ///
  /// In en, this message translates to:
  /// **'This driver already has a device. Staff must remove the old one first.'**
  String get errDriverHasActiveDevice;

  /// No description provided for @errIntegrityFailed.
  ///
  /// In en, this message translates to:
  /// **'This device did not pass the security check.'**
  String get errIntegrityFailed;

  /// No description provided for @errDeviceSignatureInvalid.
  ///
  /// In en, this message translates to:
  /// **'This device could not prove who it is. It has to be enrolled again.'**
  String get errDeviceSignatureInvalid;

  /// No description provided for @errPinLockedMinutes.
  ///
  /// In en, this message translates to:
  /// **'Too many wrong PINs. Try again in {minutes} minutes.'**
  String errPinLockedMinutes(int minutes);

  /// No description provided for @errPinLocked.
  ///
  /// In en, this message translates to:
  /// **'Too many wrong PINs. Contact staff.'**
  String get errPinLocked;

  /// No description provided for @errRateLimited.
  ///
  /// In en, this message translates to:
  /// **'Too many attempts. Wait a moment and try again.'**
  String get errRateLimited;

  /// Fallback for a code the app does not know. Shows the developer text on purpose: an unmapped code is a bug, and hiding it leaves nothing to report.
  ///
  /// In en, this message translates to:
  /// **'{code} — {message}'**
  String errUnknown(String code, String message);

  /// No description provided for @updateFailedDownload.
  ///
  /// In en, this message translates to:
  /// **'Download failed. Check your connection and try again.'**
  String get updateFailedDownload;

  /// Deliberately blunt. A file that does not match the manifest is either a broken download or someone serving something else, and the driver must not be nudged into retrying past it.
  ///
  /// In en, this message translates to:
  /// **'The update file does not match what the system published.\nPlease tell staff.'**
  String get updateFailedHashMismatch;

  /// No description provided for @updateFailedSignatureMismatch.
  ///
  /// In en, this message translates to:
  /// **'The update file was not signed by this app\'s developer.\nPlease tell staff.'**
  String get updateFailedSignatureMismatch;

  /// No description provided for @updateFailedPermissionRequired.
  ///
  /// In en, this message translates to:
  /// **'This app needs permission to install apps first.'**
  String get updateFailedPermissionRequired;

  /// No description provided for @updateFailedHandoff.
  ///
  /// In en, this message translates to:
  /// **'Could not open the installer.'**
  String get updateFailedHandoff;

  /// No description provided for @updateAvailableTitle.
  ///
  /// In en, this message translates to:
  /// **'A new version is available'**
  String get updateAvailableTitle;

  /// No description provided for @updateVersion.
  ///
  /// In en, this message translates to:
  /// **'Version {version}'**
  String updateVersion(String version);

  /// No description provided for @updateMandatory.
  ///
  /// In en, this message translates to:
  /// **'This version must be installed before you can continue.'**
  String get updateMandatory;

  /// No description provided for @updateDownloading.
  ///
  /// In en, this message translates to:
  /// **'Downloading {percent}%'**
  String updateDownloading(int percent);

  /// No description provided for @updateOpenSettings.
  ///
  /// In en, this message translates to:
  /// **'Open settings'**
  String get updateOpenSettings;

  /// No description provided for @updateNow.
  ///
  /// In en, this message translates to:
  /// **'Update now'**
  String get updateNow;

  /// No description provided for @updateSkip.
  ///
  /// In en, this message translates to:
  /// **'Skip for now'**
  String get updateSkip;
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
      <String>['en', 'th'].contains(locale.languageCode);

  @override
  bool shouldReload(_AppLocalizationsDelegate old) => false;
}

AppLocalizations lookupAppLocalizations(Locale locale) {
  // Lookup logic when only language code is specified.
  switch (locale.languageCode) {
    case 'en':
      return AppLocalizationsEn();
    case 'th':
      return AppLocalizationsTh();
  }

  throw FlutterError(
    'AppLocalizations.delegate failed to load unsupported locale "$locale". This is likely '
    'an issue with the localizations generation tool. Please file an issue '
    'on GitHub with a reproducible sample app and the gen-l10n configuration '
    'that was used.',
  );
}
