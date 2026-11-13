<?php

namespace Tests\Base\Forge\Controller;

use Base\Forge\Entity\Product\LicenseOffer;
use Base\Forge\Entity\Release;
use Base\Forge\Entity\Software;
use Base\Forge\Enum\Platform;
use Base\Forge\Enum\Pricing;
use Base\Forge\Enum\SoftwareKind;
use Base\Forge\Service\ArtifactBuilder;
use Base\Forge\Service\LicenseIssuer;
use Base\Forge\Service\ReleaseSync;
use Git\Repository\RepositoryRegistry;
use Symfony\Component\HttpFoundation\Response;
use Tests\Base\Forge\ForgeWebTestCase;
use Tests\Base\Forge\LicenseEnvironment;

/**
 * The documents the registry and the hub serve, byte for byte, against a recorded snapshot
 * (tests/Fixtures/registry-snapshot.json): a free package (a pre-release beside its stable
 * versions), a licensed one (seen anonymously, and with a key whose licence covers its first
 * version only), a draft one (unpublished: what the registry and the catalogue make of it, and a
 * package.json with neither display name nor description), a free game and a paid game with its
 * playable preview. Recorded before the registry's documents were built by omnisense/omnihub, it
 * proves they did not change: status, the headers a client reads, the body.
 *
 * What changes from a run to the next is replaced before the comparison: the random suffix of
 * the names, each tarball's hashes (they hold its package.json, which names the package - each is
 * checked against the file served first), the search's "time", the listing's "_updated", the
 * offers' ids. FORGE_SNAPSHOT_RECORD=1 writes the snapshot anew instead of comparing.
 */
class RegistrySnapshotTest extends ForgeWebTestCase
{
    use LicenseEnvironment;

    private const SNAPSHOT = __DIR__.'/../Fixtures/registry-snapshot.json';

    protected function tearDown(): void
    {
        $this->restoreLicenseEnvironment();
        parent::tearDown();
    }

    public function testTheDocumentsAreTheRecordedOnes(): void
    {
        // No signing key, whatever the host's environment holds: the manifest without licensing
        // (HubLicensingTest has it with a key).
        $this->licenseEnvironment([]);
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(3));
        $scope = 'com.snap'.$suffix;
        $hashes = [];

        $free = $this->package($suffix, 'free', Pricing::FREE, true, [
            '1.0.0' => '2026-01-10T09:00:00+00:00',
            '1.1.0' => '2026-02-10T09:00:00+00:00',
            '2.0.0-beta.1' => '2026-03-10T09:00:00+00:00',
        ], [
            'displayName' => 'Snapshot Free',
            'description' => 'A free package, its accents kept: é, à, ü.',
            'keywords' => ['snapshot', 'free'],
            'unity' => '2021.3',
            'license' => 'MIT',
            'documentationUrl' => 'https://example.org/docs/free',
            'author' => ['name' => 'Snapshot Studio', 'url' => 'https://example.org/'],
        ], $hashes);
        $free->setHomepage('https://example.org/free')->setSourceUrl('https://example.org/source/free');

        $paid = $this->package($suffix, 'paid', Pricing::LICENSED, true, [
            '1.0.0' => '2026-01-15T09:00:00+00:00',
            '1.1.0' => '2026-04-15T09:00:00+00:00',
        ], [
            'displayName' => 'Snapshot Paid',
            'description' => 'A licensed package.',
            'keywords' => ['snapshot', 'paid'],
            'unity' => '2022.3',
        ], $hashes);

        $draft = $this->package($suffix, 'draft', Pricing::FREE, false, [
            '1.0.0' => '2026-01-20T09:00:00+00:00',
        ], [
            'keywords' => [],
            'unity' => '6000.0',
        ], $hashes);

        $studio = $this->user('snapshot-studio');
        $this->em->flush();
        $freeGame = $this->game($studio, 'free-'.$suffix, Pricing::FREE, false);
        $paidGame = $this->game($studio, 'paid-'.$suffix, Pricing::LICENSED, true);

        $offer = (new LicenseOffer(null, null, 4900, 'EUR'))->setSoftware($paid)->setSeats(2)->setUpdatesMonths(1)->setDurationMonths(12);
        $offer->setTitle('Snapshot Paid, two seats');
        $paid->getOffers()->add($offer);
        $this->em->persist($offer);
        $gameOffer = (new LicenseOffer(null, null, 1500, 'EUR'))->setSoftware($paidGame)->setUpdatesMonths(null);
        $gameOffer->setTitle('Snapshot game');
        $paidGame->getOffers()->add($gameOffer);
        $this->em->persist($gameOffer);

        // A licence bought on 2026-03-01 with a month of updates: 1.0.0 is covered, 1.1.0 is not.
        $buyer = $this->user('snapshot-buyer');
        $this->em->flush();
        $issuer = static::getContainer()->get(LicenseIssuer::class);
        $license = $issuer->issue($paid, $buyer, $offer, null, new \DateTimeImmutable('2026-03-01T00:00:00+00:00'));
        $this->em->flush();
        $key = 'Bearer '.$issuer->rekey($license->getHolders()->first());
        $this->em->flush();

        $mine = fn (string $name) => str_starts_with($name, $scope.'.');
        $names = [$scope.'.free', $scope.'.paid', $scope.'.draft'];
        $slugs = [$free->getSlug(), $paid->getSlug(), $draft->getSlug(), $freeGame->getSlug(), $paidGame->getSlug()];

        $documents = [];
        foreach (['anonymous' => null, 'key' => $key] as $who => $authorization) {
            foreach ($names as $name) {
                $documents['package '.substr($name, \strlen($scope) + 1).' '.$who] = $this->capture('GET', '/upm/'.$name, $authorization);
            }
            $documents['package head free '.$who] = $this->capture('HEAD', '/upm/'.$scope.'.free', $authorization);
            $documents['search '.$who] = $this->capture('GET', '/upm/-/v1/search?text='.$scope.'&from=0&size=250', $authorization);
            $documents['search page 2 '.$who] = $this->capture('GET', '/upm/-/v1/search?text='.$scope.'&from=1&size=1', $authorization);
            $documents['all '.$who] = $this->capture('GET', '/upm/-/all', $authorization, fn (\stdClass $all) => $this->only($all, fn (string $key) => '_updated' === $key || $mine($key)));
            $documents['catalog '.$who] = $this->capture('GET', '/upm/-/hub/catalog.json', $authorization, function (\stdClass $catalog) use ($mine, $slugs) {
                $catalog->packages = array_values(array_filter($catalog->packages, fn (\stdClass $package) => $mine($package->name)));
                $catalog->games = array_values(array_filter($catalog->games, fn (\stdClass $game) => \in_array($game->slug, $slugs, true)));

                return $catalog;
            });
            foreach ([['free', '1.1.0'], ['paid', '1.0.0'], ['paid', '1.1.0']] as [$package, $version]) {
                $documents['tarball head '.$package.' '.$version.' '.$who] = $this->capture('HEAD', '/upm/'.$scope.'.'.$package.'/-/'.$scope.'.'.$package.'-'.$version.'.tgz', $authorization, null, false);
            }
        }
        $documents['package unknown'] = $this->capture('GET', '/upm/'.$scope.'.nothing');
        $documents['search wrong key'] = $this->capture('GET', '/upm/-/v1/search?text='.$scope, 'Bearer glk_0000-0000-0000-0000-0000-0000-0000-0000');
        $documents['hub'] = $this->capture('GET', '/upm/-/hub.json', null, function (\stdClass $hub) use ($scope) {
            // The scopes of every Unity package in the database: this test's, beside whatever else.
            self::assertContains($scope, $hub->sources[0]->scopes);
            $hub->sources[0]->scopes = ['<scopes>'];

            return $hub;
        });

        // Each tarball's hashes, as the files served have them, then out of the comparison.
        $replace = [];
        foreach ($hashes as $label => [$software, $version]) {
            $artifact = $software->findRelease($version)->getArtifact('upm');
            $bytes = (string) file_get_contents(static::getContainer()->get(ArtifactBuilder::class)->absolutePath($artifact));
            self::assertSame(sha1($bytes), $artifact->getSha1());
            $replace[$artifact->getSha1()] = '<sha1 '.$label.'>';
            $replace[$artifact->getIntegrity()] = '<integrity '.$label.'>';
        }
        $replace[$suffix] = 'SUFFIX';
        foreach ($documents as $label => $document) {
            if (null !== $document['body']) {
                $document['body'] = (string) preg_replace('/"time":"[A-Z][a-z]{2} [A-Z][a-z]{2} \d\d \d{4} \d\d:\d\d:\d\d GMT[+-]\d{4}"/', '"time":"<now>"', $document['body']);
                $document['body'] = (string) preg_replace('/"_updated":\d+/', '"_updated":0', $document['body']);
                $document['body'] = (string) preg_replace('/"id":('.$offer->getId().'|'.$gameOffer->getId().'),/', '"id":"<offer>",', $document['body']);
            }
            $documents[$label] = json_decode(strtr((string) json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $replace), true);
        }

        // The tarball writer's bytes for a fixed tree (a long path split into prefix and name, an
        // executable file): the same bytes, the same shasum, for every package built since.
        $documents['ustar of a fixed tree'] = sha1(ArtifactBuilder::tar([
            'package/Runtime/'.str_repeat('Deep/', 22).'Licensing.cs' => [str_repeat('x', 700), 0755],
            'package/Runtime/Licensing.cs' => ['class Licensing {}', 0644],
            'package/package.json' => ['{"name": "com.example.licensing", "version": "1.0.0"}', 0644],
        ], 1792972800));

        if (getenv('FORGE_SNAPSHOT_RECORD')) {
            file_put_contents(self::SNAPSHOT, json_encode($documents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            self::markTestIncomplete('Snapshot recorded: '.self::SNAPSHOT);
        }
        self::assertFileExists(self::SNAPSHOT, 'Record it with FORGE_SNAPSHOT_RECORD=1.');
        $recorded = json_decode((string) file_get_contents(self::SNAPSHOT), true);
        self::assertSame(array_keys($recorded), array_keys($documents), 'the same documents');
        foreach ($recorded as $label => $expected) {
            self::assertSame($expected, $documents[$label], $label);
        }
    }

    /**
     * A Unity package in a repository of its own (git's command line), a tag per version dated
     * as given, synced into releases with their tarballs.
     *
     * @param array<string, string>                             $versions version => date
     * @param array<string, mixed>                              $manifest its package.json, beside name and version
     * @param array<string, array{0: Software, 1: string}>      $hashes   the tarballs whose hashes the snapshot leaves out
     */
    private function package(string $suffix, string $label, Pricing $pricing, bool $published, array $versions, array $manifest, array &$hashes): Software
    {
        if (!\is_callable('exec') || null === (trim((string) shell_exec('command -v git')) ?: null)) {
            self::markTestSkipped('Needs the git command line to make the packages\' repositories.');
        }
        $container = static::getContainer();
        $name = 'com.snap'.$suffix.'.'.$label;
        $repository = 'upm-snapshot-'.$label.'-'.$suffix;
        $work = sys_get_temp_dir().'/forge-snapshot-'.$label.'-'.$suffix;
        $bare = rtrim($container->getParameter('forge.repositories_dir'), '/').'/'.$repository.'.git';
        @mkdir($work.'/Runtime', 0777, true);
        $run = function (string $command, string $date = '2026-01-01T00:00:00+00:00') use ($work): void {
            $dated = 'GIT_AUTHOR_DATE='.escapeshellarg($date).' GIT_COMMITTER_DATE='.escapeshellarg($date).' ';
            exec('cd '.escapeshellarg($work).' && '.$dated.$command.' 2>&1', $output, $status);
            self::assertSame(0, $status, implode("\n", $output));
        };
        $git = 'git -c user.name=Forge -c user.email=forge@example.org';
        $run('git init -q -b main .');
        file_put_contents($work.'/Runtime/Snapshot.cs', "namespace Snapshot { public static class Probe {} }\n");
        file_put_contents($work.'/Runtime/Snapshot.cs.meta', "fileFormatVersion: 2\nguid: 0123456789abcdef0123456789abcdef\n");
        foreach ($versions as $version => $date) {
            file_put_contents($work.'/CHANGELOG.md', "# Changelog\n\n## [$version]\n");
            file_put_contents($work.'/package.json', json_encode(['name' => $name, 'version' => $version] + $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $run('git add -A && '.$git.' commit -q -m '.escapeshellarg('Version '.$version), $date);
            $run($git.' tag -a v'.$version.' -m '.escapeshellarg('Changes of '.$version.': é'), $date);
        }
        @mkdir(\dirname($bare), 0777, true);
        exec('git clone -q --bare '.escapeshellarg($work).' '.escapeshellarg($bare).' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        exec('rm -rf '.escapeshellarg($work));

        $owner = $this->user('snapshot-owner');
        $this->em->flush();
        $software = (new Software($owner, 'Snapshot '.$label.' '.$suffix, 'snapshot-'.$label.'-'.$suffix))
            ->setPricing($pricing)
            ->setTagline('The '.$label.' one, as its page says.')
            ->setGitRepository($repository)
            ->setUpmName($name)
            ->publish($published);
        $this->em->persist($software);
        $this->em->flush();
        $container->get(RepositoryRegistry::class)->reset();

        $releases = $container->get(ReleaseSync::class)->sync($software);
        self::assertCount(\count($versions), $releases);
        $this->em->flush();
        foreach (array_keys($versions) as $version) {
            $hashes[$label.'@'.$version] = [$software, $version];
        }

        return $software;
    }

    private function game($owner, string $slug, Pricing $pricing, bool $preview): Software
    {
        $game = (new Software($owner, 'Snapshot game '.$slug, 'snapshot-game-'.$slug))->setKind(SoftwareKind::GAME)->setCategory('game')->setPricing($pricing)->setTagline('A game for the snapshot.')->setPlayablePreview($preview)->publish();
        $release = (new Release($game, '1.0.0'))->setPublishedAt(new \DateTimeImmutable('2026-02-01T12:00:00+00:00'))->setChangelog('First.');
        $this->em->persist($game);
        $zip = sys_get_temp_dir().'/forge-snapshot-'.bin2hex(random_bytes(4)).'.zip';
        $archive = new \ZipArchive();
        $archive->open($zip, \ZipArchive::CREATE);
        $archive->addFromString('index.html', '<!doctype html>');
        $archive->addFromString('Build/g.wasm.br', 'w');
        $archive->close();
        static::getContainer()->get(ArtifactBuilder::class)->attach($release, Platform::WEBGL, $zip, 'g.zip');
        $this->em->flush();

        return $game;
    }

    /**
     * Status, the headers a client reads and the body as served - or, for a document that lists
     * the whole database, the body with this test's entries only, encoded as the forge encodes.
     *
     * @param (callable(\stdClass): \stdClass)|null $select
     *
     * @return array{status: int, headers: array<string, string|null>, body: string|null}
     */
    private function capture(string $method, string $uri, ?string $authorization = null, ?callable $select = null, bool $body = true): array
    {
        $response = $this->request($method, $uri, $authorization ? ['HTTP_AUTHORIZATION' => $authorization] : []);
        $content = (string) $response->getContent();
        if (null !== $select && Response::HTTP_OK === $response->getStatusCode()) {
            $content = (string) json_encode($select(json_decode($content, false, 512, JSON_THROW_ON_ERROR)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $headers = [];
        foreach (['Content-Type', 'Cache-Control', 'WWW-Authenticate', 'Content-Disposition'] as $header) {
            $headers[$header] = $response->headers->get($header);
        }

        return ['status' => $response->getStatusCode(), 'headers' => $headers, 'body' => $body && 'HEAD' !== $method ? $content : null];
    }

    private function only(\stdClass $object, callable $keep): \stdClass
    {
        foreach (array_keys(get_object_vars($object)) as $key) {
            if (!$keep((string) $key)) {
                unset($object->{$key});
            }
        }

        return $object;
    }
}
