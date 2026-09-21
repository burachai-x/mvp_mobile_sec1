package com.example.driver_app

import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyPermanentlyInvalidatedException
import android.security.keystore.KeyProperties
import android.util.Base64
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.fragment.app.FragmentActivity
import java.security.KeyFactory
import java.security.KeyPairGenerator
import java.security.KeyStore
import java.security.PrivateKey
import java.security.spec.MGF1ParameterSpec
import java.security.spec.X509EncodedKeySpec
import javax.crypto.Cipher
import javax.crypto.spec.OAEPParameterSpec
import javax.crypto.spec.PSource

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
    // RSA rather than AES, and the reason is behavioural rather than
    // cryptographic. setUserAuthenticationRequired applies to every use of a
    // secret key, so an AES key demanded a fingerprint to *store* the token as
    // well as to read it — which meant unlocking with a PIN still popped the
    // sensor, and declining it failed a correct PIN. With a keypair the public
    // half seals without any prompt and only the private half is gated.
    private const val TRANSFORMATION = "RSA/ECB/OAEPWithSHA-256AndMGF1Padding"

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
     * Creates the biometric-gated keypair. Prompts once, to prove the person
     * turning this on is the one whose finger will open it.
     */
    fun enroll(activity: FragmentActivity, secret: String, onResult: (Result<String>) -> Unit) {
        clear()

        val generator = KeyPairGenerator.getInstance(KeyProperties.KEY_ALGORITHM_RSA, PROVIDER)

        generator.initialize(
            KeyGenParameterSpec.Builder(
                ALIAS,
                KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT,
            )
                .setDigests(KeyProperties.DIGEST_SHA256)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_RSA_OAEP)
                .setUserAuthenticationRequired(true)
                // Adding a fingerprint destroys the key, so a person who can
                // reach the phone's own lock screen cannot enrol their finger
                // and inherit the driver's session.
                .setInvalidatedByBiometricEnrollment(true)
                .build()
        )

        generator.generateKeyPair()

        // Confirms the sensor works and the person is present. Nothing is sealed
        // until it succeeds, so a cancelled prompt leaves the feature off rather
        // than half on.
        val probe = Cipher.getInstance(TRANSFORMATION)

        try {
            probe.init(Cipher.DECRYPT_MODE, privateKey(), oaepSpec())
        } catch (e: Throwable) {
            clear()
            onResult(Result.failure(IllegalStateException("failed")))
            return
        }

        prompt(activity, probe, "Enable fingerprint unlock") { result ->
            onResult(
                result.mapCatching {
                    seal(secret)
                }.onFailure { clear() }
            )
        }
    }

    /**
     * Encrypts with the public half, which needs no authentication.
     *
     * This is what lets the token be re-sealed after every refresh without
     * asking for a finger the driver has no reason to give again.
     */
    fun seal(secret: String): String {
        val stored = keyStore().getCertificate(ALIAS)?.publicKey
            ?: throw IllegalStateException("no_key")

        // Re-imported through the default provider. The public key handed back
        // by AndroidKeyStore carries the key's authentication requirement with
        // it, and encrypting with it would ask for a fingerprint too.
        val publicKey = KeyFactory.getInstance("RSA")
            .generatePublic(X509EncodedKeySpec(stored.encoded))

        // The same OAEP parameters as decryption, spelled out rather than left
        // to the provider. Providers disagree on the MGF1 digest — the default
        // provider picks SHA-256 while AndroidKeyStore wants SHA-1 — and the
        // mismatch only shows up at decryption, as a padding error that reads
        // exactly like a fingerprint that was not recognised.
        val cipher = Cipher.getInstance(TRANSFORMATION).apply {
            init(Cipher.ENCRYPT_MODE, publicKey, oaepSpec())
        }

        return Base64.encodeToString(cipher.doFinal(secret.toByteArray()), Base64.NO_WRAP)
    }

    /** Decrypts what [seal] produced, once a fingerprint has been accepted. */
    fun unlock(activity: FragmentActivity, sealed: String, onResult: (Result<String>) -> Unit) {
        val cipher = try {
            Cipher.getInstance(TRANSFORMATION).apply {
                init(Cipher.DECRYPT_MODE, privateKey(), oaepSpec())
            }
        } catch (_: KeyPermanentlyInvalidatedException) {
            // A fingerprint was added or removed since enrolling. The sealed
            // token is now unreadable by anyone, including us — which is the
            // behaviour we asked for. The driver falls back to the PIN.
            clear()
            onResult(Result.failure(IllegalStateException("invalidated")))
            return
        } catch (_: Throwable) {
            // Anything else here — a missing alias, a key of the wrong type
            // left over from an older build — means the stored ciphertext can
            // never be opened. Distinct from "the finger was not recognised",
            // because the driver has to set it up again rather than try harder.
            clear()
            onResult(Result.failure(IllegalStateException("unusable")))
            return
        }

        prompt(activity, cipher, "Unlock") { result ->
            onResult(
                result.fold(
                    onSuccess = { authenticated ->
                        try {
                            Result.success(String(authenticated.doFinal(Base64.decode(sealed, Base64.NO_WRAP))))
                        } catch (_: Throwable) {
                            // The finger was accepted and the key ran; what was
                            // stored simply cannot be opened — wrong padding
                            // parameters, or a value left by an older build.
                            // Reporting this as a failed prompt would tell the
                            // driver to press their finger again forever.
                            clear()
                            Result.failure(IllegalStateException("unusable"))
                        }
                    },
                    onFailure = { Result.failure(it) },
                )
            )
        }
    }

    private fun privateKey(): PrivateKey =
        keyStore().getKey(ALIAS, null) as? PrivateKey
            ?: throw IllegalStateException("no_key")

    /**
     * Used for both directions.
     *
     * AndroidKeyStore reads the OAEP digest from the transformation name but
     * leaves MGF1 on SHA-1, while other providers default it to SHA-256. Unless
     * both sides state it, sealing and opening disagree and the only symptom is
     * a padding error at decryption.
     */
    private fun oaepSpec() = OAEPParameterSpec(
        "SHA-256",
        "MGF1",
        MGF1ParameterSpec.SHA1,
        PSource.PSpecified.DEFAULT,
    )

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
