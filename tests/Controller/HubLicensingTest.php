<?php

namespace Tests\Base\Forge\Controller;

use Base\Forge\Entity\Software;
use Base\Forge\Enum\Pricing;
use Base\Forge\Enum\SoftwareKind;
use Base\Forge\Service\LicenseIssuer;
use Base\Forge\Service\LicenseToken;
use Symfony\Component\HttpFoundation\Response;
use Tests\Base\Forge\ForgeWebTestCase;
use Tests\Base\Forge\LicenseEnvironment;

/**
 * The licensing key as a client meets it (plan item 17): the hub manifest publishes the forge's
 * public key, which Forge Store pins on first use, and after a rotation the previous key with its
 * signature of the new one; a token the forge signs is bound to its registry and its package, and
 * verifies with the key the manifest published.
 */
class HubLicensingTest extends ForgeWebTestCase
{
    use LicenseEnvironment;

    protected function tearDown(): void
    {
        $this->restoreLicenseEnvironment();
        parent::tearDown();
    }

    public function testWithoutASigningKeyTheManifestAnnouncesNoKey(): void
    {
        $this->keys([]);
        $manifest = $this->json('GET', '/upm/-/hub.json');
        self::assertArrayNotHasKey('licensing', $manifest, 'nothing to pin');
        self::assertSame(503, $this->request('GET', '/api/licenses/public-key')->getStatusCode());
    }

    public function testTheManifestPublishesTheKeyAndATokenIsBoundToTheRegistry(): void
    {
        $pair = LicenseToken::keypair();
        $this->keys(['FORGE_LICENSE_SIGNING_KEY' => $pair['secret']]);

        $manifest = $this->json('GET', '/upm/-/hub.json');
        self::assertSame(['algorithm' => 'Ed25519', 'publicKey' => $pair['public'], 'registry' => $manifest['sources'][0]['url']], $manifest['licensing']);
        self::assertSame('https://localhost/upm', $manifest['licensing']['registry']);
        self::assertStringNotContainsString($pair['secret'], json_encode($manifest, JSON_UNESCAPED_SLASHES));
        self::assertSame(['algorithm' => 'Ed25519', 'public_key' => $pair['public']], $this->json('GET', '/api/licenses/public-key'));

        // A licence of a Unity package, activated as a client activates it.
        $owner = $this->user('seal-owner');
        $buyer = $this->user('seal-buyer');
        $this->em->flush();
        $suffix = bin2hex(random_bytes(3));
        $package = (new Software($owner, 'Bound '.$suffix, 'bound-'.$suffix))->setKind(SoftwareKind::PACKAGE)->setCategory('tool')->setPricing(Pricing::LICENSED)->setUpmName('com.example.bound-'.$suffix)->publish();
        $this->em->persist($package);
        $issuer = static::getContainer()->get(LicenseIssuer::class);
        $license = $issuer->issue($package, $buyer);
        $this->em->flush();
        $key = $issuer->rekey($license->findSeat((string) $buyer->getEmail()));

        $activated = $this->json('POST', '/api/licenses/activate', json_encode(['key' => $key, 'software' => $package->getSlug(), 'machine' => 'machine-0001']));
        $payload = LicenseToken::verify($activated['token'], $manifest['licensing']['publicKey']);
        self::assertNotNull($payload, 'verified with the key the manifest published');
        self::assertSame(2, $payload['v']);
        self::assertSame($manifest['licensing']['registry'], $payload['registry'], 'bound to this registry');
        self::assertSame('com.example.bound-'.$suffix, $payload['package'], 'and to this package');
        self::assertSame($package->getSlug(), $payload['software']);
        self::assertSame(hash('sha256', 'machine-0001'), $payload['machine'], 'as Forge Seal hashes a machine');
        self::assertSame($payload, $activated['license']);

        // After a rotation the manifest hands the pin over; the next token is signed with the new key.
        $next = (new LicenseToken($pair['secret']))->successor();
        $until = time() + 30 * 86400;
        $this->keys(['FORGE_LICENSE_SIGNING_KEY' => $next['secret'], 'FORGE_LICENSE_PREVIOUS_KEY' => $next['previous'], 'FORGE_LICENSE_ROTATION' => $next['rotation'], 'FORGE_LICENSE_PREVIOUS_UNTIL' => (string) $until]);
        $licensing = $this->json('GET', '/upm/-/hub.json')['licensing'];
        self::assertSame($next['public'], $licensing['publicKey']);
        self::assertSame($pair['public'], $licensing['previousKey']);
        self::assertSame($until, $licensing['previousKeyValidUntil']);
        // Forge Store's KeyPins: the pinned key's signature of "forge-key-rotation:v1:" + the announced key.
        self::assertTrue(sodium_crypto_sign_verify_detached(base64_decode($licensing['rotation']), 'forge-key-rotation:v1:'.$licensing['publicKey'], base64_decode($pair['public'])));
        self::assertSame(['algorithm' => 'Ed25519', 'public_key' => $next['public'], 'previous_key' => $pair['public'], 'rotation' => $next['rotation'], 'previous_key_valid_until' => $until], $this->json('GET', '/api/licenses/public-key'));

        $refreshed = $this->json('POST', '/api/licenses/activate', json_encode(['key' => $key, 'software' => $package->getSlug(), 'machine' => 'machine-0001']));
        self::assertNotNull(LicenseToken::verify($refreshed['token'], $next['public']));
        self::assertNull(LicenseToken::verify($refreshed['token'], $pair['public']), 'no longer signed with the previous key');
        self::assertNotNull(LicenseToken::verify($activated['token'], $licensing['previousKey']), 'a token the previous key signed still verifies with the previous key the manifest names');
    }

    public function testARotationThatDoesNotHoldIsNotPublished(): void
    {
        // Forge Store's own vector (OLD signs NEW) beside a key of this forge that is not NEW.
        $pair = LicenseToken::keypair();
        $this->keys([
            'FORGE_LICENSE_SIGNING_KEY' => $pair['secret'],
            'FORGE_LICENSE_PREVIOUS_KEY' => 'Y/T6mi9iPOI6Zz76FZg2g9W9fQWvQvFKE55o6s75pY4=',
            'FORGE_LICENSE_ROTATION' => 'Tb6sYPiqPaiKVkvqPJWI4L6D1/ZqD0OqtbHuRnUaoUULuJ2BAoOZYsxfJZIf7CSlthewgR6svrxxdbVXMEtxBA==',
        ]);
        $licensing = $this->json('GET', '/upm/-/hub.json')['licensing'];
        self::assertSame($pair['public'], $licensing['publicKey']);
        self::assertArrayNotHasKey('previousKey', $licensing, 'a client would see a plain key change, and ask its user');
        self::assertArrayNotHasKey('rotation', $licensing);
    }

    /** The forge's key material, as the vault would hand it over, in a kernel booted anew. */
    private function keys(array $values): void
    {
        $this->licenseEnvironment($values);
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
    }

    private function json(string $method, string $uri, ?string $body = null): array
    {
        $response = $this->request($method, $uri, $body ? ['CONTENT_TYPE' => 'application/json'] : [], $body);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true);
    }
}
