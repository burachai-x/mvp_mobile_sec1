import 'package:flutter/material.dart';

/// Shown before the lock screen when the device reports something risky.
///
/// It warns and does not block. These signals are self-reported and anything
/// able to act on them can also hide them, so refusing to start would punish
/// the honest phone that answers truthfully while a concealed one walks
/// straight through (CLAUDE.md §6). The server is told either way and decides
/// what it wants to do about the device.
///
/// Debugging is called out separately from root because it is the part a driver
/// can actually fix, in about four taps.
class IntegrityWarning extends StatelessWidget {
  const IntegrityWarning({
    super.key,
    required this.signals,
    required this.onRecheck,
    required this.onContinue,
    this.busy = false,
  });

  /// The flags the device reported, already filtered to the true ones.
  final List<String> signals;

  final Future<void> Function() onRecheck;
  final VoidCallback onContinue;
  final bool busy;

  static const _labels = <String, String>{
    'usb_debugging': 'เปิด USB Debugging อยู่',
    'wireless_debugging': 'เปิด Wireless Debugging อยู่',
    'developer_options': 'เปิดตัวเลือกนักพัฒนา (Developer Options) อยู่',
    'rooted': 'เครื่องนี้ถูกปลดล็อกสิทธิ์ (root)',
    'su_binary_found': 'พบไฟล์ su บนเครื่อง',
    'test_keys': 'ระบบปฏิบัติการไม่ได้ลงนามด้วยกุญแจของผู้ผลิต',
    'hook_framework_detected': 'พบเครื่องมือดักแก้การทำงานของแอป',
    'emulator': 'กำลังทำงานบนโปรแกรมจำลอง',
    'debugger_attached': 'มีตัวดีบักเชื่อมต่ออยู่',
  };

  bool get _hasDebugging => signals.any(
        (s) => s == 'usb_debugging' || s == 'wireless_debugging' || s == 'developer_options',
      );

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('คำเตือนความปลอดภัย'),
        automaticallyImplyLeading: false,
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 24, 24, 16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Icon(Icons.warning_amber_rounded, size: 64, color: Colors.amber.shade700),
              const SizedBox(height: 16),
              const Text(
                'ตรวจพบความเสี่ยงบนเครื่องนี้',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.w600),
              ),
              const SizedBox(height: 20),
              ...signals.map(
                (signal) => Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('•  '),
                      Expanded(child: Text(_labels[signal] ?? signal)),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 20),
              if (_hasDebugging) ...[
                const Text(
                  'วิธีปิด USB Debugging และ Wireless Debugging',
                  style: TextStyle(fontWeight: FontWeight.w600),
                ),
                const SizedBox(height: 8),
                const Text(
                  '1. เปิดการตั้งค่าของเครื่อง\n'
                  '2. เลือก ตัวเลือกนักพัฒนา (Developer Options)\n'
                  '    ถ้าไม่เห็นเมนูนี้ ให้ไปที่ เกี่ยวกับโทรศัพท์ (About Phone)\n'
                  '    แล้วกด เวอร์ชันอุปกรณ์ (Build Number) 7 ครั้ง\n'
                  '3. ปิด USB Debugging และ Wireless Debugging\n'
                  '4. กลับมาที่แอปแล้วกด ตรวจอีกครั้ง',
                  style: TextStyle(height: 1.6),
                ),
                const SizedBox(height: 20),
              ],
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: Colors.grey.shade100,
                  borderRadius: BorderRadius.circular(8),
                ),
                child: const Text(
                  'ระบบได้บันทึกผลตรวจนี้ไว้แล้ว และแจ้งให้เจ้าหน้าที่ทราบ',
                  style: TextStyle(fontSize: 12),
                ),
              ),
              const SizedBox(height: 24),
              FilledButton(
                onPressed: busy ? null : onRecheck,
                child: busy
                    ? const SizedBox(
                        height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
                    : const Text('ตรวจอีกครั้ง'),
              ),
              const SizedBox(height: 8),
              // Present because blocking would achieve nothing against anyone
              // who meant harm, and would strand a driver mid-shift over a
              // setting they may not be able to change.
              TextButton(
                onPressed: busy ? null : onContinue,
                child: const Text('รับทราบ ใช้งานต่อ'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
