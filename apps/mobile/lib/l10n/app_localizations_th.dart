// ignore: unused_import
import 'package:intl/intl.dart' as intl;
import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for Thai (`th`).
class AppLocalizationsTh extends AppLocalizations {
  AppLocalizationsTh([String locale = 'th']) : super(locale);

  @override
  String get signalUsbDebugging => 'เปิด USB Debugging อยู่';

  @override
  String get signalWirelessDebugging => 'เปิด Wireless Debugging อยู่';

  @override
  String get signalDeveloperOptions => 'เปิดตัวเลือกนักพัฒนาอยู่';

  @override
  String get signalDebuggerAttached => 'มีตัวดีบักเชื่อมต่ออยู่';

  @override
  String get signalRooted => 'เครื่องถูกปลดล็อกสิทธิ์ (root)';

  @override
  String get signalSuBinaryFound => 'พบไฟล์ su บนเครื่อง';

  @override
  String get signalTestKeys => 'ระบบไม่ได้ลงนามโดยผู้ผลิต';

  @override
  String get signalHookFrameworkDetected => 'พบเครื่องมือดักแก้การทำงานของแอป';

  @override
  String get signalEmulator => 'กำลังทำงานบนโปรแกรมจำลอง';

  @override
  String get integrityFatalTitle => 'ไม่สามารถใช้งานต่อได้';

  @override
  String get integrityWarnTitle => 'ตรวจพบความเสี่ยง';

  @override
  String get integrityFatalBody => 'เครื่องนี้ไม่ปลอดภัยพอสำหรับใช้งาน';

  @override
  String get integrityWarnBody => 'ควรปิดการตั้งค่าเหล่านี้ก่อนใช้งาน';

  @override
  String get integrityCloseApp => 'ปิดแอป';

  @override
  String get integrityRecheck => 'ตรวจอีกครั้ง';

  @override
  String get integrityContinue => 'ใช้งานต่อ';

  @override
  String get integrityContactStaffTitle => 'กรุณาติดต่อเจ้าหน้าที่';

  @override
  String get integrityContactStaffBody => 'เพื่อตรวจสอบเครื่องก่อนเริ่มใช้งาน';

  @override
  String get integritySteps =>
      '1.  เปิด การตั้งค่า ของเครื่อง\n2.  เลือก ตัวเลือกนักพัฒนา\n3.  ปิด USB Debugging และ Wireless Debugging\n4.  กลับมาที่แอปแล้วกด ตรวจอีกครั้ง';

  @override
  String get lockEnterPin => 'ใส่รหัส PIN';

  @override
  String get lockForgotPin => 'ลืมรหัส PIN ให้ติดต่อเจ้าหน้าที่';

  @override
  String get enrollTitle => 'ลงทะเบียนคนขับ';

  @override
  String get enrolling => 'กำลังลงทะเบียน…';

  @override
  String get enrollScanPrompt => 'สแกน QR เปิดใช้งานที่เจ้าหน้าที่ออกให้';

  @override
  String get enrollScanButton => 'สแกน QR';

  @override
  String get scanActivationQr => 'สแกน QR เปิดใช้งาน';

  @override
  String get deviceEnrolled => 'เครื่องนี้ลงทะเบียนแล้ว';

  @override
  String integrityFlagged(String signals) {
    return 'ผลตรวจสภาพเครื่องพบ: $signals';
  }

  @override
  String get integrityReported =>
      'รายงานให้เจ้าหน้าที่แล้ว ไม่ได้ปิดกั้นการลงทะเบียน';

  @override
  String get biometricTurnOff => 'ปิดการปลดล็อกด้วยลายนิ้วมือ';

  @override
  String get biometricUseNext => 'ครั้งต่อไปใช้ลายนิ้วมือ';

  @override
  String get pinSetTitle => 'ตั้งรหัส PIN 6 หลัก';

  @override
  String get pinWeakHint =>
      'เลี่ยง 123456, 000000 หรือเลขซ้ำกันทั้งหมด — เซิร์ฟเวอร์จะปฏิเสธ';

  @override
  String get confirm => 'ยืนยัน';

  @override
  String get errPinResetRequired =>
      'เจ้าหน้าที่รีเซ็ต PIN ให้แล้ว กรุณาตั้ง PIN ใหม่';

  @override
  String get errPinAlreadySet => 'เครื่องนี้ตั้ง PIN ไว้แล้ว';

  @override
  String errPinInvalidAttemptsLeft(int count) {
    return 'PIN ไม่ถูกต้อง เหลืออีก $count ครั้ง';
  }

  @override
  String get errPinInvalid => 'PIN ไม่ถูกต้อง';

  @override
  String get errActivationCodeUsed =>
      'รหัสนี้ถูกใช้ไปแล้วหรือหมดอายุ กรุณาขอรหัสใหม่จากเจ้าหน้าที่';

  @override
  String get errDriverHasActiveDevice =>
      'คนขับรายนี้มีเครื่องอยู่แล้ว เจ้าหน้าที่ต้องถอนเครื่องเดิมก่อน';

  @override
  String get errIntegrityFailed => 'เครื่องนี้ไม่ผ่านการตรวจสอบความปลอดภัย';

  @override
  String get errDeviceSignatureInvalid =>
      'เครื่องนี้ยืนยันตัวตนไม่ผ่าน ต้องลงทะเบียนใหม่';

  @override
  String errPinLockedMinutes(int minutes) {
    return 'ใส่ PIN ผิดหลายครั้ง ลองใหม่ในอีก $minutes นาที';
  }

  @override
  String get errPinLocked => 'ใส่ PIN ผิดหลายครั้ง ติดต่อเจ้าหน้าที่';

  @override
  String get errRateLimited => 'ลองมากเกินไป รอสักครู่แล้วลองใหม่';

  @override
  String errUnknown(String code, String message) {
    return '$code — $message';
  }

  @override
  String get updateFailedDownload =>
      'ดาวน์โหลดไม่สำเร็จ ตรวจสอบสัญญาณแล้วลองใหม่';

  @override
  String get updateFailedHashMismatch =>
      'ไฟล์อัปเดตไม่ตรงกับที่ระบบระบุไว้\nกรุณาแจ้งเจ้าหน้าที่';

  @override
  String get updateFailedSignatureMismatch =>
      'ไฟล์อัปเดตไม่ได้ลงนามโดยผู้พัฒนาแอปนี้\nกรุณาแจ้งเจ้าหน้าที่';

  @override
  String get updateFailedPermissionRequired =>
      'ต้องอนุญาตให้แอปนี้ติดตั้งแอปได้ก่อน';

  @override
  String get updateFailedHandoff => 'เปิดตัวติดตั้งไม่สำเร็จ';

  @override
  String get updateAvailableTitle => 'มีเวอร์ชันใหม่';

  @override
  String updateVersion(String version) {
    return 'เวอร์ชัน $version';
  }

  @override
  String get updateMandatory => 'เวอร์ชันนี้จำเป็นต้องอัปเดตก่อนใช้งาน';

  @override
  String updateDownloading(int percent) {
    return 'กำลังดาวน์โหลด $percent%';
  }

  @override
  String get updateOpenSettings => 'เปิดการตั้งค่า';

  @override
  String get updateNow => 'อัปเดตตอนนี้';

  @override
  String get updateSkip => 'ข้ามไปก่อน';
}
