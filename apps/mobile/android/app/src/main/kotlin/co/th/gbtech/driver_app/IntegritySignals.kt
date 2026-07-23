package co.th.gbtech.driver_app

import android.content.Context
import android.os.Build
import android.os.Debug
import java.io.File
import java.net.InetSocketAddress
import java.net.Socket

/**
 * What the device says about itself.
 *
 * Every check here runs inside the process being examined, so anything with
 * enough control to matter can defeat it: Magisk hides its own artifacts,
 * Zygisk denylists this app, Frida patches the checks out, and a repackaged APK
 * simply deletes them. Nothing below is evidence.
 *
 * It is worth collecting anyway, for two reasons. It shows how often devices in
 * the fleet look tampered with, which nobody would otherwise know. And it costs
 * an attacker a little more than nothing.
 *
 * The one check that is evidence is Android Key Attestation, which is signed by
 * the TEE and cannot be produced by the app (architecture.md §4.2). These
 * signals only nudge a risk score on the server and never decide alone.
 */
object IntegritySignals {

    /** Keys match what the server scores; adding one here needs the server too. */
    fun collect(context: Context): Map<String, Boolean> = mapOf(
        "rooted" to isRooted(),
        "hook_framework_detected" to hasHookFramework(),
        "emulator" to isEmulator(),
        "debugger_attached" to isDebuggerAttached(),
    )

    // ── root ────────────────────────────────────────────────────────────────

    private val SU_PATHS = listOf(
        "/sbin/su", "/system/bin/su", "/system/xbin/su", "/vendor/bin/su",
        "/su/bin/su", "/system/sd/xbin/su", "/data/local/xbin/su",
        "/data/local/bin/su", "/data/local/su",
    )

    // /cache is deliberately absent: apps cannot read it on current Android, so
    // probing it only produces an SELinux denial in the log that looks like a
    // finding and is not one.
    private val MAGISK_PATHS = listOf(
        "/sbin/.magisk", "/dev/.magisk.unblock", "/data/adb/magisk", "/data/adb/modules",
    )

    private fun isRooted(): Boolean =
        SU_PATHS.any { exists(it) } ||
            MAGISK_PATHS.any { exists(it) } ||
            // A production image is signed with release-keys. test-keys means the
            // build was signed with the public AOSP keys, which anyone holds.
            Build.TAGS?.contains("test-keys") == true ||
            canRunSu()

    /**
     * Some paths are unreadable rather than absent, and the exception itself is
     * the answer we want to avoid acting on.
     */
    private fun exists(path: String): Boolean = try {
        File(path).exists()
    } catch (_: Throwable) {
        false
    }

    private fun canRunSu(): Boolean = try {
        val process = Runtime.getRuntime().exec(arrayOf("which", "su"))
        val found = process.inputStream.bufferedReader().readLine() != null
        process.destroy()
        found
    } catch (_: Throwable) {
        false
    }

    // ── hooking ─────────────────────────────────────────────────────────────

    private val HOOK_LIBRARIES = listOf(
        "frida", "gum-js-loop", "gmain", "linjector", "substrate", "xposed",
    )

    /**
     * Looks for an injected agent in this process, and for a listening Frida
     * server on its default port.
     *
     * Both are the unconfigured case. Frida can be renamed and moved to another
     * port, which is why this is a signal and not a gate.
     */
    private fun hasHookFramework(): Boolean = mapsMentionHookLibrary() || fridaPortOpen() || xposedPresent()

    private fun mapsMentionHookLibrary(): Boolean = try {
        File("/proc/self/maps").useLines { lines ->
            lines.any { line ->
                val lowered = line.lowercase()
                HOOK_LIBRARIES.any { lowered.contains(it) }
            }
        }
    } catch (_: Throwable) {
        false
    }

    private fun fridaPortOpen(): Boolean = try {
        Socket().use { socket ->
            socket.connect(InetSocketAddress("127.0.0.1", 27042), 150)
            true
        }
    } catch (_: Throwable) {
        false
    }

    /**
     * Xposed rewrites the stack, so its classes appear in a throwable raised
     * here even when the loader is hidden from the classpath.
     */
    private fun xposedPresent(): Boolean = try {
        throw Exception("integrity-probe")
    } catch (e: Exception) {
        e.stackTrace.any {
            it.className.startsWith("de.robv.android.xposed") ||
                it.className.startsWith("com.saurik.substrate")
        }
    }

    // ── emulator ────────────────────────────────────────────────────────────

    private fun isEmulator(): Boolean =
        Build.FINGERPRINT.startsWith("generic") ||
            Build.FINGERPRINT.startsWith("unknown") ||
            Build.FINGERPRINT.contains("emulator") ||
            Build.MODEL.contains("google_sdk") ||
            Build.MODEL.contains("Emulator") ||
            Build.MODEL.contains("Android SDK built for") ||
            Build.MANUFACTURER.contains("Genymotion") ||
            Build.PRODUCT == "sdk" ||
            Build.PRODUCT.startsWith("sdk_") ||
            Build.HARDWARE.contains("goldfish") ||
            Build.HARDWARE.contains("ranchu")

    // ── debugger ────────────────────────────────────────────────────────────

    /**
     * True on a debug build being debugged, which is expected and not a finding
     * on its own. The server treats it the same as the rest: a nudge, never a
     * verdict.
     */
    private fun isDebuggerAttached(): Boolean =
        Debug.isDebuggerConnected() || Debug.waitingForDebugger()
}
