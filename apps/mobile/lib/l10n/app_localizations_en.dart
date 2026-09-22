// ignore: unused_import
import 'package:intl/intl.dart' as intl;
import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for English (`en`).
class AppLocalizationsEn extends AppLocalizations {
  AppLocalizationsEn([String locale = 'en']) : super(locale);

  @override
  String get signalUsbDebugging => 'USB debugging is on';

  @override
  String get signalWirelessDebugging => 'Wireless debugging is on';

  @override
  String get signalDeveloperOptions => 'Developer options are on';

  @override
  String get signalDebuggerAttached => 'A debugger is attached';

  @override
  String get signalRooted => 'This device is rooted';

  @override
  String get signalSuBinaryFound => 'An su binary was found on this device';

  @override
  String get signalTestKeys => 'The system was not signed by the manufacturer';

  @override
  String get signalHookFrameworkDetected =>
      'A framework that rewrites app behaviour was found';

  @override
  String get signalEmulator => 'Running on an emulator';

  @override
  String get integrityFatalTitle => 'Cannot continue';

  @override
  String get integrityWarnTitle => 'Risk detected';

  @override
  String get integrityFatalBody => 'This device is not safe enough to use';

  @override
  String get integrityWarnBody =>
      'Turn these settings off before using the app';

  @override
  String get integrityCloseApp => 'Close the app';

  @override
  String get integrityRecheck => 'Check again';

  @override
  String get integrityContinue => 'Continue anyway';

  @override
  String get integrityContactStaffTitle => 'Please contact staff';

  @override
  String get integrityContactStaffBody =>
      'so the device can be checked before you start';

  @override
  String get integritySteps =>
      '1.  Open Settings on the device\n2.  Choose Developer options\n3.  Turn off USB debugging and wireless debugging\n4.  Come back and tap Check again';

  @override
  String get lockEnterPin => 'Enter your PIN';

  @override
  String get lockForgotPin => 'If you have forgotten your PIN, contact staff';

  @override
  String get enrollTitle => 'Driver enrollment';

  @override
  String get enrolling => 'Enrolling…';

  @override
  String get enrollScanPrompt =>
      'Scan the activation QR that staff issued for you.';

  @override
  String get enrollScanButton => 'Scan QR';

  @override
  String get scanActivationQr => 'Scan activation QR';

  @override
  String get deviceEnrolled => 'This device is enrolled.';

  @override
  String integrityFlagged(String signals) {
    return 'Device checks flagged: $signals';
  }

  @override
  String get integrityReported =>
      'Reported to staff. Enrollment is not blocked by this.';

  @override
  String get biometricTurnOff => 'Turn off fingerprint unlock';

  @override
  String get biometricUseNext => 'Use fingerprint next time';

  @override
  String get pinSetTitle => 'Set a 6-digit PIN';

  @override
  String get pinWeakHint =>
      'Avoid 123456, 000000 or a repeated digit — the server rejects those.';

  @override
  String get confirm => 'Confirm';

  @override
  String get errPinResetRequired =>
      'Staff have reset your PIN. Please set a new one.';

  @override
  String get errPinAlreadySet => 'This device already has a PIN.';

  @override
  String errPinInvalidAttemptsLeft(int count) {
    return 'Wrong PIN. $count attempts left.';
  }

  @override
  String get errPinInvalid => 'Wrong PIN.';

  @override
  String get errActivationCodeUsed =>
      'This code has already been used or has expired. Ask staff for a new one.';

  @override
  String get errDriverHasActiveDevice =>
      'This driver already has a device. Staff must remove the old one first.';

  @override
  String get errIntegrityFailed =>
      'This device did not pass the security check.';

  @override
  String get errDeviceSignatureInvalid =>
      'This device could not prove who it is. It has to be enrolled again.';

  @override
  String errPinLockedMinutes(int minutes) {
    return 'Too many wrong PINs. Try again in $minutes minutes.';
  }

  @override
  String get errPinLocked => 'Too many wrong PINs. Contact staff.';

  @override
  String get errRateLimited =>
      'Too many attempts. Wait a moment and try again.';

  @override
  String errUnknown(String code, String message) {
    return '$code — $message';
  }

  @override
  String get updateFailedDownload =>
      'Download failed. Check your connection and try again.';

  @override
  String get updateFailedHashMismatch =>
      'The update file does not match what the system published.\nPlease tell staff.';

  @override
  String get updateFailedSignatureMismatch =>
      'The update file was not signed by this app\'s developer.\nPlease tell staff.';

  @override
  String get updateFailedPermissionRequired =>
      'This app needs permission to install apps first.';

  @override
  String get updateFailedHandoff => 'Could not open the installer.';

  @override
  String get updateAvailableTitle => 'A new version is available';

  @override
  String updateVersion(String version) {
    return 'Version $version';
  }

  @override
  String get updateMandatory =>
      'This version must be installed before you can continue.';

  @override
  String updateDownloading(int percent) {
    return 'Downloading $percent%';
  }

  @override
  String get updateOpenSettings => 'Open settings';

  @override
  String get updateNow => 'Update now';

  @override
  String get updateSkip => 'Skip for now';
}
