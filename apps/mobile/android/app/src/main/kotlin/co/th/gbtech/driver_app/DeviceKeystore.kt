package co.th.gbtech.driver_app

import android.os.Build
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import java.security.Signature
import java.security.spec.ECGenParameterSpec
import javax.security.auth.x500.X500Principal

/**
 * The device's identity key, held in the Android Keystore.
 *
 * The private half is generated inside the TEE and marked non-exportable, so it
 * cannot be copied off the device even on a rooted phone. That is what makes a
 * request signature mean "this device" rather than "someone who read the APK"
 * (architecture.md ADR 0001).
 *
 * Nothing here ever returns private key material — only the public key, the
 * attestation chain, and signatures.
 */
object DeviceKeystore {

    private const val ALIAS = "driver_device_key"
    private const val PROVIDER = "AndroidKeyStore"

    private fun keyStore(): KeyStore = KeyStore.getInstance(PROVIDER).apply { load(null) }

    fun hasKey(): Boolean = keyStore().containsAlias(ALIAS)

    /**
     * Generates a fresh P-256 key bound to [challenge].
     *
     * The challenge is what ties the resulting attestation to one enrollment.
     * Without it a chain captured from any genuine device could be replayed, and
     * every other check on the server would still pass (§4.2).
     *
     * Any previous key is discarded: re-enrolling means a new identity, and
     * keeping the old one around would leave a usable credential behind after a
     * device is revoked.
     */
    fun generate(challenge: ByteArray, requireStrongBox: Boolean): Map<String, Any> {
        val store = keyStore()
        if (store.containsAlias(ALIAS)) store.deleteEntry(ALIAS)

        val generator = java.security.KeyPairGenerator.getInstance(
            KeyProperties.KEY_ALGORITHM_EC, PROVIDER
        )

        val spec = KeyGenParameterSpec.Builder(ALIAS, KeyProperties.PURPOSE_SIGN)
            .setAlgorithmParameterSpec(ECGenParameterSpec("secp256r1"))
            .setDigests(KeyProperties.DIGEST_SHA256)
            .setCertificateSubject(X500Principal("CN=$ALIAS"))
            .setAttestationChallenge(challenge)
            .apply {
                // StrongBox is a separate security chip and preferable, but many
                // mid-range phones do not have one. The caller retries without it
                // rather than refusing to enroll a device that is otherwise fine.
                if (requireStrongBox && Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                    setIsStrongBoxBacked(true)
                }
            }
            .build()

        generator.initialize(spec)
        generator.generateKeyPair()

        val chain = store.getCertificateChain(ALIAS)
            ?: throw IllegalStateException("Key generated but no attestation chain was produced.")

        return mapOf(
            "publicKey" to Base64.encodeToString(
                store.getCertificate(ALIAS).publicKey.encoded, Base64.NO_WRAP
            ),
            "certificateChain" to chain.map { toPem(it.encoded) },
            "strongBox" to requireStrongBox,
        )
    }

    /**
     * Signs [payload] with the stored key.
     *
     * Returns an ASN.1 DER signature, which is what the server's
     * openssl_verify() expects for EC keys.
     */
    fun sign(payload: ByteArray): String {
        val entry = keyStore().getEntry(ALIAS, null) as? KeyStore.PrivateKeyEntry
            ?: throw IllegalStateException("No device key. Enroll first.")

        val signature = Signature.getInstance("SHA256withECDSA").apply {
            initSign(entry.privateKey)
            update(payload)
        }

        return Base64.encodeToString(signature.sign(), Base64.NO_WRAP)
    }

    fun clear() {
        val store = keyStore()
        if (store.containsAlias(ALIAS)) store.deleteEntry(ALIAS)
    }

    private fun toPem(der: ByteArray): String {
        val body = Base64.encodeToString(der, Base64.NO_WRAP).chunked(64).joinToString("\n")
        return "-----BEGIN CERTIFICATE-----\n$body\n-----END CERTIFICATE-----\n"
    }
}
