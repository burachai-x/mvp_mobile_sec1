<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Device;
use App\Models\DeviceSession;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Access and refresh tokens for an enrolled device (§6.4).
 *
 * HS256 here, unlike the activation token: only the server ever verifies these,
 * so no secret has to ship inside the APK. The reason ES256 is mandatory for
 * activation tokens simply does not apply.
 *
 * The access token stays stateless apart from a jti lookup, so revoking a device
 * takes effect on the next request rather than whenever the token expires.
 */
final class DeviceTokens
{
    private const ALGORITHM = 'HS256';

    public function __construct(private readonly string $secret) {}

    public static function make(): self
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        if ($key === '') {
            throw new RuntimeException('APP_KEY is not set.');
        }

        return new self($key);
    }

    /** @return array{access_token: string, refresh_token: string, expires_in: int, refresh_expires_in: int} */
    public function issue(Device $device, ?string $ip = null, ?string $userAgent = null): array
    {
        $accessTtl = 15 * 60;
        $refreshTtl = 30 * 24 * 60 * 60;

        $jti = (string) Str::uuid();
        $now = time();

        $accessToken = JWT::encode([
            'sub' => (string) $device->getKey(),
            'jti' => $jti,
            'iat' => $now,
            'exp' => $now + $accessTtl,
        ], $this->secret, self::ALGORITHM);

        // Opaque and high-entropy: this one is only ever compared against a
        // stored hash, so there is nothing to gain from making it structured.
        $refreshToken = Str::random(64);

        DeviceSession::create([
            'device_id' => $device->getKey(),
            'refresh_token_hash' => hash('sha256', $refreshToken),
            'access_jti' => $jti,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'expires_at' => now()->addSeconds($refreshTtl),
        ]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $accessTtl,
            'refresh_expires_in' => $refreshTtl,
        ];
    }

    /**
     * @return array{device_id: string, jti: string}
     *
     * @throws RuntimeException
     */
    public function readAccessToken(string $token): array
    {
        try {
            $claims = JWT::decode($token, new Key($this->secret, self::ALGORITHM));
        } catch (Throwable $e) {
            throw new RuntimeException('Access token is not valid.', previous: $e);
        }

        return [
            'device_id' => (string) ($claims->sub ?? ''),
            'jti' => (string) ($claims->jti ?? ''),
        ];
    }

    /**
     * Rotates the refresh token: the presented one is revoked and a new pair
     * issued.
     *
     * Reuse of an already-revoked token means it leaked, so every session for
     * that device is killed rather than just refusing this one request (§6.4).
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, refresh_expires_in: int}
     */
    public function rotate(string $refreshToken, ?string $ip = null, ?string $userAgent = null): array
    {
        $hash = hash('sha256', $refreshToken);

        $session = DeviceSession::query()->where('refresh_token_hash', $hash)->first();

        if ($session === null) {
            throw new RuntimeException('Refresh token is not valid.');
        }

        if ($session->revoked_at !== null) {
            DeviceSession::query()
                ->where('device_id', $session->device_id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            throw new RuntimeException('Refresh token was already used; all sessions revoked.');
        }

        if ($session->expires_at->isPast()) {
            throw new RuntimeException('Refresh token has expired.');
        }

        $device = Device::query()->whereKey($session->device_id)->firstOrFail();

        $session->update(['revoked_at' => now()]);

        return $this->issue($device, $ip, $userAgent);
    }

    public function revokeAllFor(Device $device): void
    {
        DeviceSession::query()
            ->where('device_id', $device->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}
