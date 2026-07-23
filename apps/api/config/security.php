<?php

declare(strict_types=1);

/**
 * Security keys and policy knobs.
 *
 * Keys deliberately do NOT reuse APP_KEY: DOCUMENT_KEK and
 * NATIONAL_ID_ENCRYPTION_KEY are mounted only into portal/worker, never into the
 * internet-facing api container (ADR 0007). Sharing APP_KEY would undo that.
 */
return [

    // HMAC pepper for device UUIDs and national IDs.
    // Changing this after go-live invalidates every stored hash — there is no
    // migration path, lookups and dedupe simply stop matching.
    'device_uuid_pepper' => env('DEVICE_UUID_PEPPER'),

    // AES-256-GCM key for national ID at rest (§5).
    'national_id_key' => env('NATIONAL_ID_ENCRYPTION_KEY'),

    // ES256 keypair for the activation QR token (§6.2).
    // Asymmetric on purpose: an HS256 shared secret would have to ship inside
    // the APK, and anyone extracting it could mint their own activation codes.
    'activation_jwt_private_key' => env('ACTIVATION_JWT_PRIVATE_KEY'),
    'activation_jwt_public_key' => env('ACTIVATION_JWT_PUBLIC_KEY'),

    // Key-encryption key wrapping each document's DEK (ADR 0005).
    // Losing it makes every stored document unrecoverable — that is by design,
    // and is what makes crypto-shredding work (§10.3).
    'document_kek' => env('DOCUMENT_KEK'),
    'document_kek_version' => (int) env('DOCUMENT_KEK_VERSION', 1),

    // SHA-256 fingerprints of accepted signing certs (PRD §2.3).
    // Must accept several at once so a key rotation does not 403 every existing
    // device on release day (§6.8).
    'app_signature_allowlist' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('APP_SIGNATURE_SHA256_ALLOWLIST', '')))
    )),

    // Read through config, not env(), because `php artisan config:cache`
    // (mandatory in production per CLAUDE.md §5) stops .env from being loaded at
    // runtime. An env() call there silently falls back to its default, which for
    // a version gate means the check quietly turns itself off.
    'min_supported_app_version' => (int) env('MIN_SUPPORTED_APP_VERSION', 1),

    'rate_limits' => [
        'enroll_per_hour' => 10,
        'pin_setup_per_hour' => 5,
        'pin_verify_per_minute' => 10,
    ],

    'request_signature' => [
        // Accepted clock skew for X-Timestamp, seconds.
        'max_skew' => 60,
        // How long a nonce stays unusable, seconds. Must exceed max_skew*2.
        'nonce_ttl' => 300,
    ],

    'pin' => [
        'length' => 6,
        // 10^6 possibilities only, so lockout is what actually protects it (§6.4).
        'max_attempts' => 5,
        'lockout_minutes' => 15,
        'block_after_total_failures' => 10,
    ],

    'staff_pin' => [
        'max_attempts' => 5,
        'lockout_minutes' => 15,
        // Elevated session TTL, minutes (§9.2). Set to 0 to demand the PIN on
        // every single action.
        'elevation_ttl' => 10,

        // Alert thresholds per staff member per hour (§9.4). These warn, they do
        // not block: a legitimate busy afternoon must not be cut off, and the
        // point is that someone sees the pattern, not that the request fails.
        'reveal_alert_per_hour' => 60,
        'download_alert_per_hour' => 30,
    ],

    // Android Key Attestation. MVP runs in monitor mode: record and alert, but
    // still allow enrollment, until we know what real driver devices look like (§4.2).
    'attestation' => [
        'enforce' => (bool) env('KEY_ATTESTATION_ENFORCE', false),
    ],

    'documents' => [
        'max_bytes' => 10 * 1024 * 1024,
        'allowed_mime' => ['image/jpeg', 'image/png', 'application/pdf'],
    ],
];
