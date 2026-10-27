<?php

namespace Tests\Base\Forge\Controller;

use Base\Forge\Entity\Release;
use Base\Forge\Entity\Software;
use Base\Forge\Enum\Platform;
use Base\Forge\Enum\Pricing;
use Base\Forge\Enum\SoftwareKind;
use Base\Forge\Service\ArtifactBuilder;
use Base\Forge\Service\LicenseIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\Base\Forge\ForgeWebTestCase;

/**
 * What the hub clients read (in a host application): the hub manifest, the catalogue - a paid
 * package listed with what it costs, installable once a key covers it; a free game played in the
 * browser, a paid one not - and the device flow that hands an editor its user's key.
 */
class HubTest extends ForgeWebTestCase
{
    public function testTheManifestNamesTheRegistryAndHowToBuy(): void
    {
        $manifest = $this->json('GET', '/upm/-/hub.json');
        self::assertSame(1, $manifest['version']);
        self::assertSame('upm', $manifest['sources'][0]['type']);
        self::assertSame('https://localhost/upm', $manifest['sources'][0]['url']);
        self::assertSame('https://localhost/upm/-/hub/catalog.json', $manifest['sources'][0]['catalog']);
        self::assertSame('https://localhost/upm/-/hub/device', $manifest['checkout']['device']);
        self::assertSame('https://localhost/upm/-/hub/token', $manifest['checkout']['token']);
        self::assertSame(['list' => 'https://localhost/upm/-/hub/licenses', 'activate' => 'https://localhost/api/licenses/activate', 'release' => 'https://localhost/api/licenses/release'], $manifest['licenses']);
    }

    public function testTheCatalogueAndTheDeviceFlow(): void
    {
        $studio = $this->user('studio');
        $buyer = $this->user('buyer');
        $this->em->flush();
        $suffix = bin2hex(random_bytes(3));
        $free = $this->game($studio, 'free-'.$suffix, Pricing::FREE);
        $paid = $this->game($studio, 'paid-'.$suffix, Pricing::LICENSED);

        $games = array_column($this->json('GET', '/upm/-/hub/catalog.json')['games'], null, 'slug');
        self::assertSame('https://localhost/play/'.$free->getSlug().'/1.0.0/', $games[$free->getSlug()]['play'], 'a free game plays in the browser');
        self::assertArrayNotHasKey('play', $games[$paid->getSlug()], 'a paid one is downloaded once bought');
        self::assertSame(['webgl'], $games[$paid->getSlug()]['platforms']);

        // The editor asks for a code; nothing yet.
        $device = $this->json('POST', '/upm/-/hub/device', '{"item":"com.glitchr.nothing"}');
        self::assertMatchesRegularExpression('/^[B-Z]{4}-[B-Z]{4}$/', $device['user_code']);
        self::assertStringContainsString('/hub/link?code='.$device['user_code'], $device['verification_uri_complete']);
        $pending = $this->request('POST', '/upm/-/hub/token', ['CONTENT_TYPE' => 'application/json'], json_encode(['device_code' => $device['device_code']]));
        self::assertSame(400, $pending->getStatusCode());
        self::assertSame('authorization_pending', json_decode((string) $pending->getContent(), true)['error']);

        // The user, signed in, without a licence: nothing to link.
        $this->signIn($buyer);
        $page = $this->request('GET', '/hub/link?code='.$device['user_code']);
        self::assertSame(200, $page->getStatusCode(), substr(strip_tags((string) $page->getContent()), 0, 1500));
        self::assertSame(0, $this->xpath($page)->query('//form[@method="post"]//input[@name="code"]')->length);

        // With a licence of the paid game: linked, and the editor receives a key that opens it.
        static::getContainer()->get(LicenseIssuer::class)->issue(static::getContainer()->get(EntityManagerInterface::class)->find(Software::class, $paid->getId()), static::getContainer()->get(EntityManagerInterface::class)->find($buyer::class, $buyer->getId()));
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $page = $this->xpath($this->request('GET', '/hub/link?code='.$device['user_code']));
        $token = $page->evaluate('string(//form[@method="post"]//input[@name="_token"]/@value)');
        self::assertNotSame('', $token, 'the link form');
        $linked = $this->request('POST', '/hub/link', [], null, ['code' => $device['user_code'], '_token' => $token]);
        self::assertSame(200, $linked->getStatusCode());
        self::assertStringContainsString('forge-badge', (string) $linked->getContent(), 'linked');
        $this->cookies = [];
        $key = $this->json('POST', '/upm/-/hub/token', json_encode(['device_code' => $device['device_code']]));
        self::assertMatchesRegularExpression('/^glk_/', $key['key']);
        self::assertSame($buyer->getEmail(), $key['email']);
        self::assertSame('https://localhost/upm', $key['registry']);
        $again = $this->request('POST', '/upm/-/hub/token', ['CONTENT_TYPE' => 'application/json'], json_encode(['device_code' => $device['device_code']]));
        self::assertSame('expired_token', json_decode((string) $again->getContent(), true)['error'], 'a key is handed over once');

        // Its licences, and the machines of its seat once one is activated.
        $licenses = $this->json('GET', '/upm/-/hub/licenses', null, $key['key']);
        self::assertSame($buyer->getEmail(), $licenses['email']);
        self::assertSame($paid->getSlug(), $licenses['licenses'][0]['software']['slug']);
        self::assertSame([], $licenses['licenses'][0]['machines']);
        self::assertSame(401, $this->request('GET', '/upm/-/hub/licenses')->getStatusCode(), 'no key, no licences');

        // That key is the registry's token now.
        self::assertSame(200, $this->request('GET', '/upm/-/hub/catalog.json', ['HTTP_AUTHORIZATION' => 'Bearer '.$key['key']])->getStatusCode());
        self::assertSame(401, $this->request('GET', '/upm/-/hub/catalog.json', ['HTTP_AUTHORIZATION' => 'Bearer glk_0000-0000-0000-0000-0000-0000-0000-0000'])->getStatusCode());
    }

    private function game($owner, string $slug, Pricing $pricing): Software
    {
        $game = (new Software($owner, 'Hub game '.$slug, 'hub-game-'.$slug))->setKind(SoftwareKind::GAME)->setCategory('game')->setPricing($pricing)->publish();
        $release = (new Release($game, '1.0.0'))->setPublishedAt(new \DateTimeImmutable('-1 hour'));
        $this->em->persist($game);
        $zip = sys_get_temp_dir().'/forge-hub-'.bin2hex(random_bytes(4)).'.zip';
        $archive = new \ZipArchive();
        $archive->open($zip, \ZipArchive::CREATE);
        $archive->addFromString('index.html', '<!doctype html>');
        $archive->addFromString('Build/g.wasm.br', 'w');
        $archive->close();
        static::getContainer()->get(ArtifactBuilder::class)->attach($release, Platform::WEBGL, $zip, 'g.zip');
        $this->em->flush();

        return $game;
    }

    private function json(string $method, string $uri, ?string $body = null, ?string $key = null): array
    {
        $response = $this->request($method, $uri, ($body ? ['CONTENT_TYPE' => 'application/json'] : []) + ($key ? ['HTTP_AUTHORIZATION' => 'Bearer '.$key] : []), $body);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true);
    }
}
