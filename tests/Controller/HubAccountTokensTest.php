<?php

namespace Tests\Base\Forge\Controller;

use Base\Entity\User;
use Base\Forge\Entity\HubToken;
use Base\Forge\Entity\Software;
use Base\Forge\Enum\LicenseStatus;
use Base\Forge\Enum\Pricing;
use Base\Forge\Enum\SoftwareKind;
use Base\Forge\Service\LicenseIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\Base\Forge\ForgeWebTestCase;

/**
 * The editors linked to an account, as Forge Store and Forge Hub see them (docs/hub.md, "Linked
 * editors and signing out"), in a host application: each device flow approved is a token of its
 * own; a client lists them, signs another out, signs itself out; the account's page unlinks one;
 * and the account's token lists the account's licences, a seat's key its own.
 */
class HubAccountTokensTest extends ForgeWebTestCase
{
    public function testAnEditorListsTheLinkedOnesSignsAnotherOutThenItself(): void
    {
        $buyer = $this->user('linked');
        $other = $this->user('other');
        $this->em->flush();
        $first = $this->link($buyer);
        $second = $this->link($buyer);
        $theirs = $this->link($other);

        // Every token of the account, newest first, the one sending the request said: no secret, the prefix only.
        $list = $this->json('GET', '/upm/-/hub/account-tokens', $first);
        self::assertSame([substr($second, 0, 12), substr($first, 0, 12)], array_column($list['tokens'], 'prefix'));
        self::assertSame([false, true], array_column($list['tokens'], 'current'));
        self::assertStringNotContainsString(substr($first, 12), json_encode($list), 'never the token itself');
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $list['tokens'][0]['created']);
        [$secondId, $firstId] = array_column($list['tokens'], 'id');
        $theirsId = $this->json('GET', '/upm/-/hub/account-tokens', $theirs)['tokens'][0]['id'];

        // Another account's token: not one of this account's - nothing revoked.
        self::assertSame(['error' => 'unknown_account_token'], $this->json('DELETE', '/upm/-/hub/account-tokens/'.$theirsId, $first, Response::HTTP_NOT_FOUND));
        $this->json('GET', '/upm/-/hub/account-tokens', $theirs);

        // The second editor signed out from the first: 401 from then on.
        self::assertSame(204, $this->request('DELETE', '/upm/-/hub/account-tokens/'.$secondId, $this->bearer($first))->getStatusCode());
        $this->unauthorized('GET', '/upm/-/hub/account-tokens', $second);
        self::assertSame([$firstId], array_column($this->json('GET', '/upm/-/hub/account-tokens', $first)['tokens'], 'id'));

        // The first signs itself out.
        self::assertSame(204, $this->request('DELETE', '/upm/-/hub/account-tokens/current', $this->bearer($first))->getStatusCode());
        $this->unauthorized('GET', '/upm/-/hub/account-tokens', $first);
        $this->unauthorized('DELETE', '/upm/-/hub/account-tokens/current', $first);
        $this->unauthorized('GET', '/upm/-/hub/account-tokens', null);
        self::assertSame([], $this->em->getRepository(HubToken::class)->findBy(['user' => $buyer]));
    }

    public function testTheAccountsPageUnlinksAnEditorItNoLongerHas(): void
    {
        $buyer = $this->user('page');
        $this->em->flush();
        $token = $this->link($buyer);
        $id = $this->json('GET', '/upm/-/hub/account-tokens', $token)['tokens'][0]['id'];

        $this->signIn($buyer);
        $page = $this->request('GET', '/compte/licences');
        self::assertSame(200, $page->getStatusCode());
        $xpath = $this->xpath($page);
        self::assertSame(substr($token, 0, 12).'…', trim($xpath->evaluate('string(//section[@id="editeurs"]//code)')));
        $csrf = $xpath->evaluate('string(//section[@id="editeurs"]//form/input[@name="_token"]/@value)');

        self::assertSame(403, $this->request('POST', '/compte/editeurs/'.$id.'/retirer', [], null, ['_token' => 'not-it'])->getStatusCode(), 'its form only');
        $done = $this->request('POST', '/compte/editeurs/'.$id.'/retirer', [], null, ['_token' => $csrf]);
        self::assertSame(302, $done->getStatusCode());
        $this->cookies = [];
        $this->unauthorized('GET', '/upm/-/hub/account-tokens', $token);
    }

    public function testTheAccountsTokenListsTheAccountsLicences(): void
    {
        $buyer = $this->user('licences');
        $this->em->flush();
        $token = $this->link($buyer);

        // Linked without a licence: none.
        self::assertSame(['email' => $buyer->getEmail(), 'licenses' => []], $this->json('GET', '/upm/-/hub/licenses', $token));

        // Its licences once it holds one, the machines of its seat with them - as the seat's key lists them.
        $suffix = bin2hex(random_bytes(3));
        $software = (new Software($this->user('studio'), 'Listed '.$suffix, 'listed-'.$suffix))->setKind(SoftwareKind::PACKAGE)->setCategory('tool')->setPricing(Pricing::LICENSED)->setUpmName('com.example.listed-'.$suffix)->publish();
        $this->em->persist($software);
        $this->em->flush();
        $issuer = static::getContainer()->get(LicenseIssuer::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $license = $issuer->issue($em->find(Software::class, $software->getId()), $em->find($buyer::class, $buyer->getId()));
        $em->flush();
        $key = $issuer->rekey($license->findSeatOf($em->find($buyer::class, $buyer->getId())));

        $byAccount = $this->json('GET', '/upm/-/hub/licenses', $token);
        self::assertSame($buyer->getEmail(), $byAccount['email']);
        self::assertSame([$software->getSlug()], array_map(static fn (array $l) => $l['software']['slug'], $byAccount['licenses']));
        self::assertSame($this->json('GET', '/upm/-/hub/licenses', $key), $byAccount, 'the same answer as the seat\'s key');

        // A revoked licence is not listed.
        $em->find($license::class, $license->getId())->setStatus(LicenseStatus::REVOKED);
        $em->flush();
        self::assertSame([], $this->json('GET', '/upm/-/hub/licenses', $token)['licenses']);

        // A token that is not one of this forge's: the account's realm; no credential: the registry's, as before.
        $unknown = $this->request('GET', '/upm/-/hub/licenses', $this->bearer('gla_0000-0000-0000-0000-0000-0000-0000-0000'));
        self::assertSame([401, 'Bearer realm="Forge account"'], [$unknown->getStatusCode(), $unknown->headers->get('WWW-Authenticate')]);
        self::assertSame(401, $this->request('GET', '/upm/-/hub/licenses')->getStatusCode());

        // It still opens no package.
        self::assertSame(401, $this->request('GET', '/upm/-/hub/catalog.json', $this->bearer($token))->getStatusCode());
    }

    /** The device flow, as an editor and its user go through it: a new account's token. */
    private function link(User $user): string
    {
        $cookies = $this->cookies;
        $device = $this->json('POST', '/upm/-/hub/device', null, Response::HTTP_OK, '{"item":"com.example.anything"}');
        $this->signIn($user);
        $page = $this->xpath($this->request('GET', '/hub/link?code='.$device['user_code']));
        $csrf = $page->evaluate('string(//form[@method="post"]//input[@name="_token"]/@value)');
        self::assertSame(200, $this->request('POST', '/hub/link', [], null, ['code' => $device['user_code'], '_token' => $csrf])->getStatusCode());
        $this->cookies = [];
        $token = $this->json('POST', '/upm/-/hub/token', null, Response::HTTP_OK, json_encode(['device_code' => $device['device_code']]))['account_token'];
        $this->cookies = $cookies;

        return $token;
    }

    /** @return array<string, string> */
    private function bearer(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    private function unauthorized(string $method, string $uri, ?string $token): void
    {
        $response = $this->request($method, $uri, null !== $token ? $this->bearer($token) : []);
        self::assertSame(401, $response->getStatusCode(), $method.' '.$uri);
        self::assertSame('Bearer realm="Forge account"', $response->headers->get('WWW-Authenticate'));
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $uri, ?string $token, int $status = Response::HTTP_OK, ?string $body = null): array
    {
        $response = $this->request($method, $uri, (null !== $token ? $this->bearer($token) : []) + (null !== $body ? ['CONTENT_TYPE' => 'application/json'] : []), $body);
        self::assertSame($status, $response->getStatusCode(), $method.' '.$uri.': '.substr((string) $response->getContent(), 0, 2000));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), 'never cached');

        return json_decode((string) $response->getContent(), true) ?? [];
    }
}
