import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'l10n/app_localizations.dart';

/// Signals that mean the platform underneath the app cannot be trusted.
///
/// A driver cannot undo any of these from a settings screen, so there is
/// nothing to tell them to fix — the app stops and staff take a look.
const fatalSignals = {
  'rooted',
  'su_binary_found',
  'test_keys',
  'hook_framework_detected',
  'emulator',
};

/// Whether the flags found mean the app must not run at all.
bool signalsAreFatal(Iterable<String> signals) => signals.any(fatalSignals.contains);

/// Shown ahead of everything when the device reports something risky.
///
/// Two outcomes, because the two kinds of finding are not alike. Debugging left
/// on is a setting the driver can turn off in four taps, so it warns and lets
/// them carry on; a rooted or hooked platform is not theirs to repair, so the
/// app closes.
///
/// None of this is evidence. These signals are self-reported, and anything able
/// to act on them can also hide them — someone who meant harm patches the check
/// out and never sees this screen. What it reliably does is keep an honest
/// phone in a known state and tell the driver how to get there.
///
/// The server is never bound by it. It scores the same signals but can only
/// ever reach a warning from them, because a forgeable value must not decide
/// whether a device is blocked (CLAUDE.md §6).
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

  bool get _fatal => signalsAreFatal(signals);

  /// Short enough to sit on one line each. Thai does not break on spaces the
  /// way Flutter's line breaker expects, so a long sentence wraps in the middle
  /// of a word — every string behind these keys is short or broken by hand.
  ///
  /// An unknown signal falls through to its raw name rather than being hidden:
  /// a flag the app has no wording for is still a flag the driver should see.
  String _label(AppLocalizations t, String signal) => switch (signal) {
        'usb_debugging' => t.signalUsbDebugging,
        'wireless_debugging' => t.signalWirelessDebugging,
        'developer_options' => t.signalDeveloperOptions,
        'debugger_attached' => t.signalDebuggerAttached,
        'rooted' => t.signalRooted,
        'su_binary_found' => t.signalSuBinaryFound,
        'test_keys' => t.signalTestKeys,
        'hook_framework_detected' => t.signalHookFrameworkDetected,
        'emulator' => t.signalEmulator,
        _ => signal,
      };

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.white,
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // The content takes what is left after the buttons, and scrolls
              // only if it genuinely cannot fit. An earlier version squeezed
              // the list into a fixed share of the screen, which silently
              // overflowed and printed the guidance on top of the findings once
              // there were four of them.
              Expanded(
                child: LayoutBuilder(
                  builder: (context, constraints) => SingleChildScrollView(
                    child: ConstrainedBox(
                      constraints: BoxConstraints(minHeight: constraints.maxHeight),
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          _icon(),
                          const SizedBox(height: 20),
                          Text(
                            _fatal
                                ? AppLocalizations.of(context)!.integrityFatalTitle
                                : AppLocalizations.of(context)!.integrityWarnTitle,
                            textAlign: TextAlign.center,
                            style: const TextStyle(
                                fontSize: 22, fontWeight: FontWeight.w700, height: 1.3),
                          ),
                          const SizedBox(height: 6),
                          Text(
                            _fatal
                                ? AppLocalizations.of(context)!.integrityFatalBody
                                : AppLocalizations.of(context)!.integrityWarnBody,
                            textAlign: TextAlign.center,
                            style: TextStyle(fontSize: 14, color: Colors.grey.shade600),
                          ),
                          const SizedBox(height: 22),
                          _findings(context),
                          const SizedBox(height: 22),
                          _guidance(context),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 16),
              ..._actions(context),
            ],
          ),
        ),
      ),
    );
  }

  List<Widget> _actions(BuildContext context) {
    if (_fatal) {
      return [
        SizedBox(
          height: 52,
          child: FilledButton(
            // The only way out. Reopening the app is what re-runs the checks.
            onPressed: () => SystemNavigator.pop(),
            child: Text(AppLocalizations.of(context)!.integrityCloseApp,
                style: const TextStyle(fontSize: 16)),
          ),
        ),
      ];
    }

    return [
      SizedBox(
        height: 52,
        child: FilledButton(
          // Re-reads the signals in place, so a driver who just turned
          // debugging off does not have to work out that a restart is needed.
          onPressed: busy ? null : onRecheck,
          child: busy
              ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
              : Text(AppLocalizations.of(context)!.integrityRecheck,
                  style: const TextStyle(fontSize: 16)),
        ),
      ),
      const SizedBox(height: 4),
      TextButton(
        onPressed: busy ? null : onContinue,
        child: Text(AppLocalizations.of(context)!.integrityContinue),
      ),
    ];
  }

  Widget _icon() => Center(
        child: Container(
          width: 76,
          height: 76,
          decoration: BoxDecoration(
            color: _fatal ? Colors.red.shade50 : Colors.amber.shade50,
            shape: BoxShape.circle,
          ),
          child: Icon(
            _fatal ? Icons.gpp_bad_outlined : Icons.warning_amber_rounded,
            size: 40,
            color: _fatal ? Colors.red.shade700 : Colors.amber.shade800,
          ),
        ),
      );

  /// One finding per line, left aligned.
  ///
  /// Previously these were joined with separators into a single sentence, which
  /// wrapped mid-word and left the reader counting dots to work out how many
  /// problems there were.
  Widget _findings(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        decoration: BoxDecoration(
          color: Colors.grey.shade100,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            for (final signal in signals)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 5),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Padding(
                      padding: const EdgeInsets.only(top: 6, right: 10),
                      child: Container(
                        width: 6,
                        height: 6,
                        decoration: BoxDecoration(
                          // Marks which findings are the reason the app is
                          // stopping, when the list mixes both kinds.
                          color: fatalSignals.contains(signal)
                              ? Colors.red.shade600
                              : Colors.amber.shade800,
                          shape: BoxShape.circle,
                        ),
                      ),
                    ),
                    Expanded(
                      child: Text(
                        _label(AppLocalizations.of(context)!, signal),
                        style: const TextStyle(fontSize: 14, height: 1.4),
                      ),
                    ),
                  ],
                ),
              ),
          ],
        ),
      );

  Widget _guidance(BuildContext context) => _fatal
      ? Column(
          children: [
            Text(
              AppLocalizations.of(context)!.integrityContactStaffTitle,
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 6),
            Text(
              AppLocalizations.of(context)!.integrityContactStaffBody,
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 14, height: 1.5),
            ),
          ],
        )
      // Numbered by hand rather than as a list widget: the steps have to break
      // exactly where written, or Thai wraps mid-word.
      : Text(
          AppLocalizations.of(context)!.integritySteps,
          style: const TextStyle(fontSize: 14, height: 1.9),
        );
}
