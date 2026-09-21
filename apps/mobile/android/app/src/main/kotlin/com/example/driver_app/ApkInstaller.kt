package com.example.driver_app

import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import androidx.core.content.FileProvider
import java.io.File
import java.security.MessageDigest

/**
 * Reads an APK's signing certificate, and hands it to the installer.
 *
 * The certificate check is the last of the six in architecture.md §11.3 and the
 * only one that cannot be done in Dart: it means parsing the APK signing block,
 * which the platform already does. Android enforces the same rule when
 * installing over an existing app, but checking first turns an unexplained
 * failure into a message, and catches the older trick of persuading someone to
 * uninstall before installing a forgery.
 */
object ApkInstaller {

    /**
     * SHA-256 of the certificate that signed [path], lowercase hex.
     *
     * Null when the file cannot be read as an APK at all, which is itself a
     * reason to refuse it.
     */
    fun signingCertificateSha256(context: Context, path: String): String? =
        certificateOf(context, path)

    /** The same for the running app, so an update can be checked against itself. */
    fun ownSigningCertificateSha256(context: Context): String? =
        certificateOf(context, null)

    private fun certificateOf(context: Context, path: String?): String? = try {
        val flags = PackageManager.GET_SIGNING_CERTIFICATES

        val info = if (path == null) {
            context.packageManager.getPackageInfo(context.packageName, flags)
        } else {
            context.packageManager.getPackageArchiveInfo(path, flags)
        }

        // apkContentsSigners rather than the history: the history includes keys
        // this app was signed with in the past, and an update must carry the
        // key it is signed with now.
        val signers = info?.signingInfo?.apkContentsSigners

        if (signers.isNullOrEmpty()) {
            null
        } else {
            MessageDigest.getInstance("SHA-256")
                .digest(signers[0].toByteArray())
                .joinToString("") { "%02x".format(it) }
        }
    } catch (_: Throwable) {
        null
    }

    /**
     * Whether the user has allowed this app to install packages.
     *
     * Since Android 8 the permission in the manifest is only half of it; the
     * user grants the rest in settings. Asking first means the driver is sent
     * to the right screen rather than watching an install silently do nothing.
     */
    fun canInstall(context: Context): Boolean = context.packageManager.canRequestPackageInstalls()

    /** Opens the settings page where that permission is granted. */
    fun openInstallPermissionSettings(context: Context) {
        context.startActivity(
            Intent(
                android.provider.Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                android.net.Uri.parse("package:${context.packageName}"),
            ).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        )
    }

    /**
     * Hands the file to the system installer.
     *
     * Only ever called once the hash and the certificate have both matched —
     * see UpdateInstaller on the Dart side. Reaching this with an unverified
     * file is the whole risk this feature carries.
     */
    fun install(context: Context, path: String) {
        val uri = FileProvider.getUriForFile(
            context,
            "${context.packageName}.updates",
            File(path),
        )

        context.startActivity(
            Intent(Intent.ACTION_VIEW)
                .setDataAndType(uri, "application/vnd.android.package-archive")
                .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        )
    }
}
