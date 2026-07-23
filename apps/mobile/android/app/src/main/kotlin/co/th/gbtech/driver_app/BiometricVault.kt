package co.th.gbtech.driver_app

import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyPermanentlyInvalidatedException
import android.security.keystore.KeyProperties
import android.util.Base64
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.fragment.app.FragmentActivity
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Holds the refresh token behind the fingerprint sensor.
 *
 * The important part is what the fingerprint actually gates. A biometric API
 * that returns "authenticated: true" gates nothing — a hooked app ignores the
 * answer and carries on. Here the token is encrypted with an AES key inside the
 * Keystore marked [setUserAuthenticationRequired], and the TEE refuses to run
 * that key until a biometric matches. Skipping the prompt does not skip the
 * decryption; it leaves the app with ciphertext.
 *
 * The token itself still means nothing on its own: spending it needs a request
 * signed by the device key, and the server rotates it and can revoke it. A
 * fingerprint decides whether the app may reach the credential, never whether
 * the session is valid.
 */
object BiometricVault {

    private const val ALIAS = "driver_biometric_key"
    private const val PROVIDER = "AndroidKeyStore"
    private const val TRANSFORMATION = "AES/GCM/NoPadding"
    private const val TAG_BITS = 128

    private val ALLOWED = BiometricManager.Authenticators.BIOMETRIC_STRONG

    private fun keyStore(): KeyStore = KeyStore.getInstance(PROVIDER).apply { load(null) }

    /**
     * Whether the device has a usable strong biometric enrolled.
     *
     * BIOMETRIC_STRONG only: a class 2 sensor cannot gate a Keystore key, so
     * accepting one would mean the prompt appears and protects nothing.
     */
    fun availability(activity: FragmentActivity): String {
        val status = BiometricManager.from(activity).canAuthenticate(ALLOWED)

        return when (status) {
            BiometricManager.BIOMETRIC_SUCCESS -> "available"
            BiometricManager.BIOMETRIC_ERROR_NONE_ENROLLED -> "none_enrolled"
            BiometricManager.BIOMETRIC_ERROR_NO_HARDWARE,
            BiometricManager.BIOMETRIC_ERROR_HW_UNAVAILABLE -> "no_hardware"
            BiometricManager.BIOMETRIC_ERROR_SECURITY_UPDATE_REQUIRED -> "update_required"
            // Carries the raw status so an unexpected value can be identified
            // instead of collapsing into a silent "no". BiometricManager gained
            // codes over time and this library does not know them all.
            else -> "unavailable:$status"
        }
    }

    fun hasSecret(): Boolean = keyStore().containsAlias(ALIAS)

    fun clear() {
        val store = keyStore()
        if (store.containsAlias(ALIAS)) store.deleteEntry(ALIAS)
    }

    /**
     * Encrypts [secret] behind a fresh biometric-gated key.
     *
     * A prompt is shown for this too. Enrolling without one would let an app
     * that is already unlocked bind a token to whoever's finger arrives next.
     */
    fun enroll(activity: FragmentActivity, secret: String, onResult: (Result<String>) -> Unit) {
        clear()

        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, PROVIDER)

        generator.init(
            KeyGenParameterSpec.Builder(
                ALIAS,
                KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT,
            )
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setUserAuthenticationRequired(true)
                // Adding a fingerprint destroys the key, so a person who can
                // reach the phone's own lock screen cannot enrol their finger
                // and inherit the driver's session.
                .setInvalidatedByBiometricEnrollment(true)
                .build()
        )

        val cipher = Cipher.getInstance(TRANSFORMATION).apply {
            init(Cipher.ENCRYPT_MODE, generator.generateKey())
        }

        prompt(activity, cipher, "Enable fingerprint unlock") { result ->
            onResult(
                result.mapCatching { authenticated ->
                    val encrypted = authenticated.doFinal(secret.toByteArray())

                    // The IV is not secret and is useless without the key, which
                    // never leaves the TEE.
                    val iv = Base64.encodeToString(authenticated.iv, Base64.NO_WRAP)

                    "$iv.${Base64.encodeToString(encrypted, Base64.NO_WRAP)}"
                }
            )
        }
    }

    /** Decrypts what [enroll] produced, once a fingerprint has been accepted. */
    fun unlock(activity: FragmentActivity, sealed: String, onResult: (Result<String>) -> Unit) {
        val parts = sealed.split('.')

        if (parts.size != 2) {
            onResult(Result.failure(IllegalArgumentException("Stored secret is malformed.")))
            return
        }

        val entry = keyStore().getKey(ALIAS, null) as? SecretKey

        if (entry == null) {
            onResult(Result.failure(IllegalStateException("no_key")))
            return
        }

        val cipher = try {
            Cipher.getInstance(TRANSFORMATION).apply {
                init(
                    Cipher.DECRYPT_MODE,
                    entry,
                    GCMParameterSpec(TAG_BITS, Base64.decode(parts[0], Base64.NO_WRAP)),
                )
            }
        } catch (_: KeyPermanentlyInvalidatedException) {
            // A fingerprint was added or removed since enrolling. The stored
            // token is now unreadable by anyone, including us — which is the
            // behaviour we asked for. The driver falls back to the PIN.
            clear()
            onResult(Result.failure(IllegalStateException("invalidated")))
            return
        }

        prompt(activity, cipher, "Unlock") { result ->
            onResult(
                result.mapCatching { authenticated ->
                    String(authenticated.doFinal(Base64.decode(parts[1], Base64.NO_WRAP)))
                }
            )
        }
    }

    private fun prompt(
        activity: FragmentActivity,
        cipher: Cipher,
        title: String,
        onResult: (Result<Cipher>) -> Unit,
    ) {
        val prompt = BiometricPrompt(
            activity,
            androidx.core.content.ContextCompat.getMainExecutor(activity),
            object : BiometricPrompt.AuthenticationCallback() {
                override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                    val unlocked = result.cryptoObject?.cipher

                    // Without the CryptoObject the prompt proved nothing usable:
                    // it would be an answer we chose to believe rather than a key
                    // the TEE released.
                    if (unlocked == null) {
                        onResult(Result.failure(IllegalStateException("no_crypto_object")))
                    } else {
                        onResult(Result.success(unlocked))
                    }
                }

                override fun onAuthenticationError(code: Int, message: CharSequence) {
                    onResult(Result.failure(IllegalStateException(errorName(code))))
                }
            },
        )

        prompt.authenticate(
            BiometricPrompt.PromptInfo.Builder()
                .setTitle(title)
                .setSubtitle("Use your fingerprint to continue")
                .setNegativeButtonText("Use PIN")
                .setAllowedAuthenticators(ALLOWED)
                .build(),
            BiometricPrompt.CryptoObject(cipher),
        )
    }

    /** Named so the app can tell "user cancelled" from "sensor locked out". */
    private fun errorName(code: Int): String = when (code) {
        BiometricPrompt.ERROR_NEGATIVE_BUTTON,
        BiometricPrompt.ERROR_USER_CANCELED,
        BiometricPrompt.ERROR_CANCELED -> "cancelled"
        BiometricPrompt.ERROR_LOCKOUT,
        BiometricPrompt.ERROR_LOCKOUT_PERMANENT -> "lockout"
        BiometricPrompt.ERROR_NO_BIOMETRICS -> "none_enrolled"
        else -> "failed"
    }
}
