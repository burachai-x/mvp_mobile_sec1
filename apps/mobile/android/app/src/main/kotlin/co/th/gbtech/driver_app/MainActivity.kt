package co.th.gbtech.driver_app

import android.util.Base64
import io.flutter.embedding.android.FlutterFragmentActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

// FlutterFragmentActivity, not FlutterActivity: BiometricPrompt is built on
// FragmentActivity and cannot attach to anything else.
class MainActivity : FlutterFragmentActivity() {

    private companion object {
        const val CHANNEL = "co.th.gbtech.driver_app/keystore"
    }

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, CHANNEL)
            .setMethodCallHandler { call, result ->
                try {
                    when (call.method) {
                        "hasKey" -> result.success(DeviceKeystore.hasKey())

                        // Model and OS version only. Never a hardware serial or
                        // ANDROID_ID: those are personal data under PDPA and the
                        // server anchors identity on the key, not an identifier.
                        "deviceInfo" -> result.success(
                            mapOf(
                                "platform" to "android",
                                "model" to "${android.os.Build.MANUFACTURER} ${android.os.Build.MODEL}",
                                "os_version" to "Android ${android.os.Build.VERSION.RELEASE}",
                            )
                        )

                        "generate" -> {
                            val challenge = Base64.decode(
                                call.argument<String>("challenge") ?: "", Base64.NO_WRAP
                            )
                            val strongBox = call.argument<Boolean>("strongBox") ?: false
                            result.success(DeviceKeystore.generate(challenge, strongBox))
                        }

                        "integritySignals" -> result.success(IntegritySignals.collect(applicationContext))

                        "sign" -> {
                            val payload = call.argument<String>("payload") ?: ""
                            result.success(DeviceKeystore.sign(payload.toByteArray()))
                        }

                        "clear" -> {
                            DeviceKeystore.clear()
                            BiometricVault.clear()
                            result.success(null)
                        }

                        "biometricAvailability" ->
                            result.success(BiometricVault.availability(this))

                        "biometricHasSecret" -> result.success(BiometricVault.hasSecret())

                        "biometricForget" -> {
                            BiometricVault.clear()
                            result.success(null)
                        }

                        // The prompt is asynchronous, so these two reply from the
                        // callback rather than returning.
                        "biometricEnroll" -> BiometricVault.enroll(
                            this,
                            call.argument<String>("secret") ?: "",
                        ) { outcome -> reply(result, outcome) }

                        // No prompt: the public half seals, so re-sealing after a
                        // token rotates costs the driver nothing.
                        "biometricSeal" ->
                            result.success(BiometricVault.seal(call.argument<String>("secret") ?: ""))

                        "apkSigningCertificate" -> result.success(
                            ApkInstaller.signingCertificateSha256(
                                applicationContext,
                                call.argument<String>("path") ?: "",
                            )
                        )

                        "ownSigningCertificate" ->
                            result.success(ApkInstaller.ownSigningCertificateSha256(applicationContext))

                        "canInstallPackages" -> result.success(ApkInstaller.canInstall(applicationContext))

                        "openInstallPermission" -> {
                            ApkInstaller.openInstallPermissionSettings(this)
                            result.success(null)
                        }

                        "installApk" -> {
                            ApkInstaller.install(applicationContext, call.argument<String>("path") ?: "")
                            result.success(null)
                        }

                        "biometricUnlock" -> BiometricVault.unlock(
                            this,
                            call.argument<String>("sealed") ?: "",
                        ) { outcome -> reply(result, outcome) }

                        else -> result.notImplemented()
                    }
                } catch (e: Throwable) {
                    // Surfaced to Dart so the UI can explain what failed. The
                    // message never contains key material — DeviceKeystore only
                    // ever handles public values and signatures.
                    result.error("keystore_error", e.message, e::class.java.simpleName)
                }
            }
    }

    /**
     * Errors carry the reason as the code so Dart can tell a cancelled prompt
     * from a locked-out sensor from a key destroyed by a new fingerprint. The
     * message is never key material — only public values cross this channel.
     */
    private fun reply(result: MethodChannel.Result, outcome: Result<String>) {
        outcome.fold(
            onSuccess = { result.success(it) },
            onFailure = { error ->
                // Only codes Dart knows how to act on. Passing an exception
                // message through as the code meant anything unforeseen arrived
                // as an unrecognised value and was treated as a retryable
                // "finger not recognised", which some failures never are.
                val known = setOf("cancelled", "lockout", "none_enrolled", "invalidated", "unusable")
                val code = error.message.takeIf { it in known } ?: "failed"

                result.error(code, error.message, null)
            },
        )
    }
}
