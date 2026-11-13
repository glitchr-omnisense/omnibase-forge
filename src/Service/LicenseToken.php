<?php

namespace Base\Forge\Service;

use Base\Forge\Entity\LicenseActivation;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The proof an activated machine keeps, to run without asking again: what
 * the licence allows, for that machine, until when - signed with the
 * studio's Ed25519 key, so the software checks it offline with the public
 * key it ships with, and nothing it holds can forge one.
 *
 *     token = base64url(payload JSON) "." base64url(Ed25519 signature of the payload JSON)
 *
 * The payload:
 *   v                2 (1 before the registry and the package were bound into it)
 *   license          the licence's id
 *   software         the software's slug - a token for another is refused
 *   registry         the Unity registry's address (UpmRegistry::url(), the hub manifest's
 *                    licensing.registry): a token is valid only for that registry's packages
 *   package          the Unity package it covers (Software::$upmName), or null: not a package
 *   email            the seat's e-mail
 *   machine          SHA-256 of the machine identifier - the software compares it to its own
 *   issued_at        UNIX time
 *   valid_until      UNIX time: past it, the software asks again (FORGE_LICENSE_GRACE_DAYS,
 *                    never beyond the licence's own expiry)
 *   license_expires  UNIX time, or null: perpetual
 *   updates_until    UNIX time, or null: the releases published until then are covered
 *
 * The secret key is FORGE_LICENSE_SIGNING_KEY (base64 of sodium's 64-byte
 * secret key): a secret, in the vault, never in the repository, never
 * printed. bin/console forge:license:keypair seals the first one there,
 * forge:license:rotate replaces it and seals beside it what the hub manifest
 * publishes of the change (rotation()): the previous public key, its
 * signature of the new one, and until when the tokens it signed may run.
 */
class LicenseToken
{
    /** What a store's previous key signs to hand over to its new key: this, then the new key in base64 (Forge Store's HubLicensing.RotationPrefix). */
    public const ROTATION_PREFIX = 'forge-key-rotation:v1:';
    public const VERSION = 2;

    public function __construct(
        #[Autowire('%env(default::FORGE_LICENSE_SIGNING_KEY)%')] #[\SensitiveParameter] private readonly ?string $secretKey = null,
        #[Autowire('%env(default::FORGE_LICENSE_GRACE_DAYS)%')] private readonly ?string $graceDays = null,
        #[Autowire('%env(default::FORGE_LICENSE_PREVIOUS_KEY)%')] private readonly ?string $previousKey = null,
        #[Autowire('%env(default::FORGE_LICENSE_ROTATION)%')] private readonly ?string $rotation = null,
        #[Autowire('%env(default::FORGE_LICENSE_PREVIOUS_UNTIL)%')] private readonly ?string $previousUntil = null,
        private readonly ?UpmRegistry $registry = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return null !== $this->secret();
    }

    /** The public key the software ships with, base64. */
    public function publicKey(): ?string
    {
        $secret = $this->secret();

        return $secret ? base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)) : null;
    }

    /** How long a token runs after its activation, in days: FORGE_LICENSE_GRACE_DAYS, 30 by default. */
    public function graceDays(): int
    {
        return max(1, (int) $this->graceDays ?: 30);
    }

    /**
     * The last rotation, as the hub manifest publishes it - only when it holds: the previous
     * public key, its Ed25519 signature of ROTATION_PREFIX + the current public key (a client
     * that pinned the previous key moves to the current one without asking its user), and the
     * end of the previous key's tokens (UNIX time: the rotation plus the grace days, or the
     * rotation itself when that key was compromised). Null without a rotation, or when what the
     * vault holds does not verify against the current key.
     *
     * @return array{previousKey: string, rotation: string, previousKeyValidUntil: ?int}|null
     */
    public function rotation(): ?array
    {
        $current = $this->publicKey();
        $previous = self::canonicalKey($this->previousKey);
        $signature = trim((string) $this->rotation);
        if (null === $current || null === $previous || $previous === $current || !self::rotationVerifies($previous, $signature, $current)) {
            return null;
        }

        return [
            'previousKey' => $previous,
            'rotation' => $signature,
            'previousKeyValidUntil' => ctype_digit(trim((string) $this->previousUntil)) ? (int) trim((string) $this->previousUntil) : null,
        ];
    }

    /** A key's short mark, as Forge Store shows it to compare two by eye: the first 8 bytes of its SHA-256, in hex pairs. */
    public static function fingerprint(string $publicKey): string
    {
        $bytes = base64_decode(trim($publicKey), true);

        return false === $bytes ? '?' : implode(':', str_split(substr(hash('sha256', $bytes), 0, 16), 2));
    }

    /** The text a previous key signs to hand over to <$publicKey> (base64). */
    public static function rotationMessage(string $publicKey): string
    {
        return self::ROTATION_PREFIX.$publicKey;
    }

    /** Whether <$signature> (base64) is <$previous>'s signature of the rotation to <$current> (both base64): Forge Store's check. */
    public static function rotationVerifies(string $previous, string $signature, string $current): bool
    {
        $key = base64_decode(trim($previous), true);
        $bytes = base64_decode(trim($signature), true);
        $current = self::canonicalKey($current);
        if (false === $key || false === $bytes || null === $current || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== \strlen($key) || SODIUM_CRYPTO_SIGN_BYTES !== \strlen($bytes)) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($bytes, self::rotationMessage($current), $key);
    }

    /**
     * A new pair to take over from the current key: the new secret (for the vault only), the new
     * public key, the current public key and its signature of the new one. The current secret
     * never leaves this object.
     *
     * @return array{secret: string, public: string, previous: string, rotation: string}
     */
    public function successor(): array
    {
        $old = $this->secret() ?? throw new \LogicException('FORGE_LICENSE_SIGNING_KEY is not set: bin/console forge:license:keypair');
        $pair = self::keypair();

        return $pair + [
            'previous' => base64_encode(sodium_crypto_sign_publickey_from_secretkey($old)),
            'rotation' => base64_encode(sodium_crypto_sign_detached(self::rotationMessage($pair['public']), $old)),
        ];
    }

    /**
     * @return array{token: string, payload: array<string, mixed>}
     */
    public function issue(LicenseActivation $activation, ?\DateTimeImmutable $at = null): array
    {
        $secret = $this->secret() ?? throw new \LogicException('FORGE_LICENSE_SIGNING_KEY is not set: bin/console forge:license:keypair');
        $at ??= new \DateTimeImmutable();
        $seat = $activation->getSeat();
        $license = $seat?->getLicense() ?? throw new \LogicException('An activation without its licence.');

        $validUntil = $at->modify(sprintf('+%d days', $this->graceDays()));
        if ($license->getExpiresAt() && $license->getExpiresAt() < $validUntil) {
            $validUntil = $license->getExpiresAt();
        }

        $payload = [
            'v' => self::VERSION,
            'license' => $license->getId(),
            'software' => $license->getSoftware()?->getSlug(),
            // Bound to this registry and this package (plan item 17.2): a token another store
            // signed never unlocks a package of the same name installed from this one.
            'registry' => $this->registry?->url(),
            'package' => $license->getSoftware()?->getUpmName(),
            'email' => $seat->getEmail(),
            'machine' => $activation->getMachine(),
            'issued_at' => $at->getTimestamp(),
            'valid_until' => $validUntil->getTimestamp(),
            'license_expires' => $license->getExpiresAt()?->getTimestamp(),
            'updates_until' => $license->getUpdatesUntil()?->getTimestamp(),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return [
            'token' => self::encode($json).'.'.self::encode(sodium_crypto_sign_detached($json, $secret)),
            'payload' => $payload,
        ];
    }

    /**
     * What the software does, offline: the payload when the signature is the
     * studio's, else null. Here for the tests and for tools written in PHP.
     *
     * @return array<string, mixed>|null
     */
    public static function verify(string $token, string $publicKey): ?array
    {
        $parts = explode('.', $token);
        if (2 !== \count($parts)) {
            return null;
        }
        $json = self::decode($parts[0]);
        $signature = self::decode($parts[1]);
        $key = base64_decode($publicKey, true);
        if (false === $json || false === $signature || false === $key || SODIUM_CRYPTO_SIGN_BYTES !== \strlen($signature) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== \strlen($key)) {
            return null;
        }
        if (!sodium_crypto_sign_verify_detached($signature, $json, $key)) {
            return null;
        }

        return json_decode($json, true);
    }

    /** @return array{secret: string, public: string} a new pair, base64 */
    public static function keypair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ];
    }

    private function secret(): ?string
    {
        if (!$this->secretKey) {
            return null;
        }
        $secret = base64_decode(trim($this->secretKey), true);

        return false !== $secret && SODIUM_CRYPTO_SIGN_SECRETKEYBYTES === \strlen($secret) ? $secret : null;
    }

    /** A 32-byte public key in base64, written as PHP and C# write it; null when it is none. */
    private static function canonicalKey(?string $key): ?string
    {
        $bytes = base64_decode(trim((string) $key), true);

        return false !== $bytes && SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === \strlen($bytes) ? base64_encode($bytes) : null;
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $text): string|false
    {
        return base64_decode(strtr($text, '-_', '+/'), true);
    }
}
