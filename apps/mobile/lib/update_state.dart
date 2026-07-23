import 'package:shared_preferences/shared_preferences.dart';

import 'update_manifest.dart';

/// What the device remembers between update checks.
///
/// Two values, and both only ever move one way.
class UpdateState {
  UpdateState(this._prefs);

  static const _sequenceKey = 'manifest_sequence';
  static const _pinningKey = 'certificate_pinning_enabled';

  final SharedPreferences _prefs;

  static Future<UpdateState> load() async => UpdateState(await SharedPreferences.getInstance());

  /// Highest manifest sequence accepted so far.
  ///
  /// On a rooted phone this file can be edited, which would allow an old
  /// manifest to be replayed. That is a real limit, not a solved problem — the
  /// expiry on the manifest is what bounds the damage, since a replayed old
  /// manifest is usually an expired one. It is recorded in the threat model
  /// rather than hidden here.
  int get sequence => _prefs.getInt(_sequenceKey) ?? 0;

  /// Whether certificate pinning is still enforced (§11.5, kill switch).
  ///
  /// Defaults to on, so a device that has never seen a manifest — or whose
  /// stored state was wiped — pins. Only a verified manifest can turn it off.
  bool get pinningEnabled => _prefs.getBool(_pinningKey) ?? true;

  /// Records a manifest that passed every check.
  Future<void> accept(UpdateManifest manifest) async {
    if (manifest.sequence < sequence) {
      // The verifier already refuses these; this is here so a future caller
      // cannot walk the counter backwards by accident. Equal is allowed: the
      // same manifest arrives on every launch until it is replaced.
      throw ArgumentError.value(manifest.sequence, 'sequence', 'Must not precede $sequence.');
    }

    await _prefs.setInt(_sequenceKey, manifest.sequence);
    await _prefs.setBool(_pinningKey, manifest.certificatePinningEnabled);
  }
}
