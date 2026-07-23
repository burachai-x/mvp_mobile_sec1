import 'package:flutter/material.dart';

import 'update_installer.dart';
import 'update_manifest.dart';

/// Offers the update a verified manifest described.
///
/// Nothing here decides whether the update is genuine — that was settled before
/// this screen appeared, and is settled again on the file itself before the
/// installer is ever called. This only shows progress and explains a refusal.
class UpdateScreen extends StatefulWidget {
  const UpdateScreen({
    super.key,
    required this.manifest,
    required this.installer,
    required this.onSkip,
    required this.mandatory,
  });

  final UpdateManifest manifest;
  final UpdateInstaller installer;

  /// Null when the driver may not carry on without updating.
  final VoidCallback? onSkip;

  final bool mandatory;

  @override
  State<UpdateScreen> createState() => _UpdateScreenState();
}

class _UpdateScreenState extends State<UpdateScreen> {
  double? _progress;
  String? _error;
  bool _needsPermission = false;

  Future<void> _start() async {
    setState(() {
      _progress = 0;
      _error = null;
      _needsPermission = false;
    });

    try {
      await widget.installer.download(
        widget.manifest,
        onProgress: (received, total) {
          if (mounted && total > 0) setState(() => _progress = received / total);
        },
      );

      await widget.installer.install(widget.manifest);

      // The system installer takes over here. If the driver declines it, the
      // app is still running and the button can be pressed again.
      if (mounted) setState(() => _progress = null);
    } on InstallRefused catch (e) {
      if (!mounted) return;

      setState(() {
        _progress = null;
        _needsPermission = e.reason == InstallFailure.permissionRequired;
        _error = switch (e.reason) {
          InstallFailure.download => 'ดาวน์โหลดไม่สำเร็จ ตรวจสอบสัญญาณแล้วลองใหม่',
          // Deliberately blunt. A file that does not match the manifest is
          // either a broken download or someone serving something else, and
          // the driver should not be nudged into retrying past it.
          InstallFailure.hashMismatch =>
            'ไฟล์อัปเดตไม่ตรงกับที่ระบบระบุไว้\nกรุณาแจ้งเจ้าหน้าที่',
          InstallFailure.signatureMismatch =>
            'ไฟล์อัปเดตไม่ได้ลงนามโดยผู้พัฒนาแอปนี้\nกรุณาแจ้งเจ้าหน้าที่',
          InstallFailure.permissionRequired =>
            'ต้องอนุญาตให้แอปนี้ติดตั้งแอปได้ก่อน',
          InstallFailure.handoff => 'เปิดตัวติดตั้งไม่สำเร็จ',
        };
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final busy = _progress != null;

    return Scaffold(
      backgroundColor: Colors.white,
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(
                child: LayoutBuilder(
                  builder: (context, constraints) => SingleChildScrollView(
                    child: ConstrainedBox(
                      constraints: BoxConstraints(minHeight: constraints.maxHeight),
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Center(
                            child: Container(
                              width: 76,
                              height: 76,
                              decoration: BoxDecoration(
                                color: Colors.green.shade50,
                                shape: BoxShape.circle,
                              ),
                              child: Icon(
                                Icons.system_update,
                                size: 38,
                                color: Colors.green.shade700,
                              ),
                            ),
                          ),
                          const SizedBox(height: 20),
                          const Text(
                            'มีเวอร์ชันใหม่',
                            textAlign: TextAlign.center,
                            style: TextStyle(fontSize: 22, fontWeight: FontWeight.w700),
                          ),
                          const SizedBox(height: 6),
                          Text(
                            'เวอร์ชัน ${widget.manifest.latestVersion}',
                            textAlign: TextAlign.center,
                            style: TextStyle(fontSize: 14, color: Colors.grey.shade600),
                          ),
                          if (widget.manifest.releaseNotesTh != null) ...[
                            const SizedBox(height: 22),
                            Container(
                              padding: const EdgeInsets.all(16),
                              decoration: BoxDecoration(
                                color: Colors.grey.shade100,
                                borderRadius: BorderRadius.circular(12),
                              ),
                              child: Text(
                                widget.manifest.releaseNotesTh!,
                                style: const TextStyle(fontSize: 14, height: 1.6),
                              ),
                            ),
                          ],
                          if (widget.mandatory) ...[
                            const SizedBox(height: 20),
                            const Text(
                              'เวอร์ชันนี้จำเป็นต้องอัปเดตก่อนใช้งาน',
                              textAlign: TextAlign.center,
                              style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600),
                            ),
                          ],
                          if (busy) ...[
                            const SizedBox(height: 24),
                            LinearProgressIndicator(value: _progress),
                            const SizedBox(height: 8),
                            Text(
                              'กำลังดาวน์โหลด ${((_progress ?? 0) * 100).round()}%',
                              textAlign: TextAlign.center,
                              style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
                            ),
                          ],
                          if (_error != null) ...[
                            const SizedBox(height: 20),
                            Text(
                              _error!,
                              textAlign: TextAlign.center,
                              style: TextStyle(color: Colors.red.shade700, height: 1.5),
                            ),
                          ],
                        ],
                      ),
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 16),
              SizedBox(
                height: 52,
                child: FilledButton(
                  onPressed: busy
                      ? null
                      : _needsPermission
                          ? widget.installer.openPermissionSettings
                          : _start,
                  child: Text(
                    _needsPermission ? 'เปิดการตั้งค่า' : 'อัปเดตตอนนี้',
                    style: const TextStyle(fontSize: 16),
                  ),
                ),
              ),
              if (widget.onSkip != null) ...[
                const SizedBox(height: 4),
                TextButton(
                  onPressed: busy ? null : widget.onSkip,
                  child: const Text('ข้ามไปก่อน'),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
