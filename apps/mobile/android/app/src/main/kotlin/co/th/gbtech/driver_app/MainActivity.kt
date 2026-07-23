package co.th.gbtech.driver_app

import android.util.Base64
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterActivity() {

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
                            result.success(null)
                        }

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
}
