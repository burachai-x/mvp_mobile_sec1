<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ActivationCode;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use Throwable;

/**
 * The JWT carried by the activation QR code (§6.2).
 *
 * ES256, not HS256: a shared secret would have to ship inside the APK, where
 * `strings` finds it in seconds and anyone can then mint their own activation
 * codes. With an asymmetric key the app verifies nothing and the server holds
 * the only thing that can sign.
 *
 * The row stores sha256(jwt) rather than the token, so a database leak does not
 * hand out usable codes.
 */
final class ActivationToken
{
    private const ALGORITHM = 'ES256';
    private const AUDIENCE = 'enroll';

    public function __construct(
        private readonly ?string $privateKeyPem,
        private readonly ?string $publicKeyPem,
    ) {}

    public static function make(): self
    {
        $private = (string) config('security.activation_jwt_private_key');
        $public = (string) config('security.activation_jwt_public_key');

        return new self($private !== '' ? $private : null, $public !== '' ? $public : null);
    }

    /** @return array{token: string, token_hash: string, expires_at: int} */
    public function issue(ActivationCode $code, int $ttlMinutes = 15): array
    {
        $key = $this->privateKeyPem;

        if ($key === null) {
            throw new RuntimeException(
                'ACTIVATION_JWT_PRIVATE_KEY is not configured. Run `make activation-keys` '
                .'and put the result in .env (or a Docker secret in production).'
            );
        }

        $now = time();
        $expiresAt = $now + ($ttlMinutes * 60);

        $token = JWT::encode([
            'aud' => self::AUDIENCE,
            'iat' => $now,
            'exp' => $expiresAt,
            // Ties the token to one row so a second QR cannot redeem the same code.
            'jti' => (string) $code->getKey(),
            'code' => $code->code,
        ], $key, self::ALGORITHM);

        return [
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array{jti: string, code: string}
     *
     * @throws RuntimeException when the token is forged, expired or malformed
     */
    public function verify(string $token): array
    {
        $key = $this->publicKeyPem;

        if ($key === null) {
            throw new RuntimeException('ACTIVATION_JWT_PUBLIC_KEY is not configured.');
        }

        try {
            $claims = JWT::decode($token, new Key($key, self::ALGORITHM));
        } catch (Throwable $e) {
            // Signature, expiry and structure failures all land here and all mean
            // the same thing to the caller: do not enroll. Keeping them
            // indistinguishable avoids handing an attacker a tuning signal.
            throw new RuntimeException('Activation token is not valid.', previous: $e);
        }

        if (($claims->aud ?? null) !== self::AUDIENCE) {
            // A token minted for some other purpose must not work here, even
            // when signed by the same key.
            throw new RuntimeException('Activation token is not valid.');
        }

        return [
            'jti' => (string) ($claims->jti ?? ''),
            'code' => (string) ($claims->code ?? ''),
        ];
    }

    /** Constant-time check of a presented token against the stored hash. */
    public function matchesStoredHash(string $token, string $storedHash): bool
    {
        return hash_equals($storedHash, hash('sha256', $token));
    }
}
