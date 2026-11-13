<?php

namespace Tests\Base\Forge\Service;

use Base\Forge\Service\LicenseToken;
use PHPUnit\Framework\TestCase;

/**
 * The signing key's rotation and the token format, checked against the clients' own test vectors:
 * Forge Store's (io.glitchr.store, Tests/Editor/TrustTests.cs) for the rotation, Forge Seal's
 * (io.glitchr.licensing, Tests/Editor/LicenseTokenTests.cs) for the token. The key pairs made here
 * live for the test only.
 */
class LicenseTokenTest extends TestCase
{
    // Forge Store's throwaway public keys: ROTATION is OLD's signature of "forge-key-rotation:v1:" + NEW.
    private const STORE_OLD = 'Y/T6mi9iPOI6Zz76FZg2g9W9fQWvQvFKE55o6s75pY4=';
    private const STORE_NEW = '5Wr6MvD/0ktioPo2QsSyiHKTCPeraqZf94+MwEYZ6cc=';
    private const STORE_ROTATION = 'Tb6sYPiqPaiKVkvqPJWI4L6D1/ZqD0OqtbHuRnUaoUULuJ2BAoOZYsxfJZIf7CSlthewgR6svrxxdbVXMEtxBA==';
    private const STORE_OTHER = 'AQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyA=';

    // Forge Seal's token, as this forge signed it (format 1), and its public key.
    private const SEAL_PUBLIC_KEY = '6kpsY+KcUgq+9VB7Ey7F+ZVHdq6+vnuSQh7qaRRG0iw=';
    private const SEAL_TOKEN = 'eyJ2IjoxLCJsaWNlbnNlIjo0Miwic29mdHdhcmUiOiJwcm9iZS1nYW1lIiwiZW1haWwiOiJhZGFAZXhhbXBsZS5vcmciLCJtYWNoaW5lIjoiMzE3ZjA2Zjc3YmY3MmUwMmNiNjgyYzM2NWZkNWViMDQwMGM2NDc5NTA2NTI4ZDc0NWY1ZWMzZjIzYjA0Y2FhMSIsImlzc3VlZF9hdCI6MTc5Mjk3MjgwMCwidmFsaWRfdW50aWwiOjE3OTU1NjQ4MDAsImxpY2Vuc2VfZXhwaXJlcyI6bnVsbCwidXBkYXRlc191bnRpbCI6MTgyNDUwODgwMH0.lQZSS34z_XRwGVE22WJux9fWE4SrIKtQ6tJa0rnhdZTOHlNsWkPPL37Tj8ML_xd6FuW2FHBVWS7DZUm54rA3AA';

    public function testForgeStoresRotationVectorVerifiesHereAsThere(): void
    {
        self::assertSame('forge-key-rotation:v1:'.self::STORE_NEW, LicenseToken::rotationMessage(self::STORE_NEW));
        self::assertTrue(LicenseToken::rotationVerifies(self::STORE_OLD, self::STORE_ROTATION, self::STORE_NEW));
        self::assertTrue(LicenseToken::rotationVerifies(' '.self::STORE_OLD.' ', self::STORE_ROTATION, self::STORE_NEW), 'trimmed, as Forge Store reads it');

        self::assertFalse(LicenseToken::rotationVerifies(self::STORE_OTHER, self::STORE_ROTATION, self::STORE_NEW), 'from another key');
        self::assertFalse(LicenseToken::rotationVerifies(self::STORE_OLD, self::STORE_ROTATION, self::STORE_OTHER), 'to another key');
        self::assertFalse(LicenseToken::rotationVerifies(self::STORE_OLD, base64_encode(str_repeat("\0", 64)), self::STORE_NEW), 'a signature that does not verify');
        self::assertFalse(LicenseToken::rotationVerifies(self::STORE_OLD, 'not base64!', self::STORE_NEW));
        self::assertFalse(LicenseToken::rotationVerifies(base64_encode(str_repeat("\1", 16)), self::STORE_ROTATION, self::STORE_NEW), '16 bytes is no Ed25519 key');
    }

    public function testForgeSealsTokenVectorReadsAsForgeSealReadsIt(): void
    {
        $payload = LicenseToken::verify(self::SEAL_TOKEN, self::SEAL_PUBLIC_KEY);
        self::assertNotNull($payload);
        self::assertSame(1, $payload['v']);
        self::assertSame(42, $payload['license']);
        self::assertSame('probe-game', $payload['software']);
        self::assertSame(hash('sha256', 'machine-0001'), $payload['machine'], 'LicenseToken.MachineHash');
        self::assertNull($payload['license_expires']);
        self::assertNull(LicenseToken::verify(self::SEAL_TOKEN, self::STORE_NEW), 'another key');
    }

    public function testASuccessorIsSignedOverAsForgeStoreChecksIt(): void
    {
        $first = LicenseToken::keypair();
        $tokens = new LicenseToken($first['secret']);
        self::assertSame($first['public'], $tokens->publicKey());
        self::assertNull($tokens->rotation(), 'no rotation yet');

        $next = $tokens->successor();
        self::assertSame($first['public'], $next['previous']);
        self::assertNotSame($first['public'], $next['public']);
        self::assertSame(64, \strlen(base64_decode($next['rotation'], true)));
        // Forge Store's own check: the pinned (previous) key's signature of the prefix + the new key.
        self::assertTrue(sodium_crypto_sign_verify_detached(base64_decode($next['rotation']), 'forge-key-rotation:v1:'.$next['public'], base64_decode($next['previous'])));

        $rotated = new LicenseToken($next['secret'], null, $next['previous'], $next['rotation'], '1795564800');
        self::assertSame($next['public'], $rotated->publicKey());
        self::assertSame(['previousKey' => $first['public'], 'rotation' => $next['rotation'], 'previousKeyValidUntil' => 1795564800], $rotated->rotation());

        // What the vault holds is published only when it holds.
        self::assertNull((new LicenseToken($next['secret'], null, self::STORE_OLD, self::STORE_ROTATION))->rotation(), 'a rotation to another key than the current one');
        self::assertNull((new LicenseToken($first['secret'], null, $next['previous'], $next['rotation']))->rotation(), 'the vault still holds the previous secret');
        self::assertNull((new LicenseToken($next['secret'], null, $next['previous'], null))->rotation(), 'no signature');
    }

    public function testNoSuccessorWithoutAKeyAndAFingerprintAsForgeStoreShowsIt(): void
    {
        self::assertFalse((new LicenseToken())->isConfigured());
        $this->expectException(\LogicException::class);
        try {
            self::assertMatchesRegularExpression('/^([0-9a-f]{2}:){7}[0-9a-f]{2}$/', LicenseToken::fingerprint(self::STORE_NEW));
            self::assertSame(implode(':', str_split(substr(hash('sha256', base64_decode(self::STORE_NEW)), 0, 16), 2)), LicenseToken::fingerprint(' '.self::STORE_NEW));
            self::assertNotSame(LicenseToken::fingerprint(self::STORE_NEW), LicenseToken::fingerprint(self::STORE_OLD));
        } finally {
            (new LicenseToken())->successor();
        }
    }
}
