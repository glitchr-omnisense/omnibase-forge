<?php

namespace Tests\Base\Forge\Controller;

use Base\Forge\Entity\Artifact;
use Base\Forge\Entity\Download;
use Base\Forge\Entity\Software;
use Base\Forge\Enum\Pricing;
use Base\Forge\Service\ArtifactBuilder;
use Base\Forge\Service\LicenseIssuer;
use Base\Forge\Service\ReleaseSync;
use Git\Repository\RepositoryRegistry;
use Symfony\Component\HttpFoundation\Response;
use Tests\Base\Forge\ForgeWebTestCase;

/**
 * The Unity registry as Unity's Package Manager reads it (in a host application, with the git
 * command line to make the package's repository): the search, the package document, the
 * tarball, for a free package and for a licensed one - refused without its key, served with
 * it (as "Authorization: Bearer", the token of .upmconfig.toml, or Basic e-mail:key).
 */
class UpmRegistryTest extends ForgeWebTestCase
{
    public function testAFreePackageIsListedDescribedAndInstalled(): void
    {
        [$software, $name] = $this->package(Pricing::FREE, ['1.0.0', '1.1.0']);

        $search = $this->get('/upm/-/v1/search?text=com.glitchr&from=0&size=250');
        self::assertSame(200, $search->getStatusCode());
        $objects = json_decode((string) $search->getContent(), true)['objects'];
        $found = array_values(array_filter($objects, fn (array $object) => $object['package']['name'] === $name));
        self::assertCount(1, $found, 'the scope is searched');
        self::assertSame('1.1.0', $found[0]['package']['version'], 'the newest version');
        self::assertSame('Licensing probe', $found[0]['package']['displayName']);
        $next = json_decode((string) $this->get('/upm/-/v1/search?text=com.glitchr&from=1000&size=250')->getContent(), true);
        self::assertSame([], $next['objects'], 'the page after the last is empty: the Package Manager stops there');
        self::assertArrayHasKey($name, json_decode((string) $this->get('/upm/-/all')->getContent(), true), 'the older listing too');

        $document = $this->get('/upm/'.$name);
        self::assertSame(200, $document->getStatusCode());
        $document = json_decode((string) $document->getContent(), true);
        self::assertSame($name, $document['name']);
        self::assertSame(['latest' => '1.1.0'], $document['dist-tags']);
        self::assertSame(['1.0.0', '1.1.0'], array_keys($document['versions']));
        $version = $document['versions']['1.1.0'];
        self::assertSame('1.1.0', $version['version'], 'the release\'s version, whatever the tag\'s package.json said');
        self::assertSame('2021.3', $version['unity'], 'its own package.json, read at that tag');
        self::assertSame('https://localhost/upm/'.$name.'/-/'.$name.'-1.1.0.tgz', $version['dist']['tarball']);

        $tarball = $this->get(parse_url($version['dist']['tarball'], PHP_URL_PATH));
        self::assertSame(200, $tarball->getStatusCode());
        $bytes = $this->bytes($tarball, $software, '1.1.0');
        self::assertSame($version['dist']['shasum'], sha1($bytes));
        self::assertSame($version['dist']['integrity'], 'sha512-'.base64_encode(hash('sha512', $bytes, true)));
        $manifest = json_decode($this->untar($bytes)['package/package.json'], true);
        self::assertSame(['name' => $name, 'version' => '1.1.0'], ['name' => $manifest['name'], 'version' => $manifest['version']]);
        self::assertArrayHasKey('package/Runtime/Probe.cs', $this->untar($bytes));
        self::assertSame(1, $this->em->getRepository(Download::class)->count(['channel' => 'upm', 'artifact' => $software->findRelease('1.1.0')->getArtifact('upm')]));

        // A HEAD first, as the Package Manager does: no download counted for it.
        $this->request('HEAD', parse_url($version['dist']['tarball'], PHP_URL_PATH));
        self::assertSame(1, $this->em->getRepository(Download::class)->count(['channel' => 'upm', 'artifact' => $software->findRelease('1.1.0')->getArtifact('upm')]));

        self::assertSame(404, $this->get('/upm/com.glitchr.nothing-here')->getStatusCode());
        self::assertSame(404, $this->get('/upm/'.$name.'/-/'.$name.'-9.9.9.tgz')->getStatusCode());
    }

    public function testALicensedPackageNeedsAKeyThatCoversIt(): void
    {
        [$software, $name] = $this->package(Pricing::LICENSED, ['1.0.0']);
        [$other] = $this->package(Pricing::LICENSED, ['1.0.0']);
        $buyer = $this->user('buyer');
        $stranger = $this->user('stranger');
        $this->em->flush();
        $issuer = static::getContainer()->get(LicenseIssuer::class);
        $license = $issuer->issue($software, $buyer);
        // Someone else's key, for another package: a key opens what its seat's e-mail holds.
        $otherLicense = $issuer->issue($other, $stranger);
        $this->em->flush();
        $key = $issuer->rekey($license->getHolders()->first());
        $otherKey = $issuer->rekey($otherLicense->getHolders()->first());

        // Anonymous: not listed, its document and its tarball a 401.
        self::assertNotContains($name, $this->names($this->get('/upm/-/v1/search?text=com.glitchr')));
        self::assertSame(401, ($refused = $this->get('/upm/'.$name))->getStatusCode());
        self::assertStringContainsString('Bearer', (string) $refused->headers->get('WWW-Authenticate'));
        self::assertSame(401, $this->get('/upm/'.$name.'/-/'.$name.'-1.0.0.tgz')->getStatusCode());

        // A key that is no one's: 401 everywhere, even the search.
        self::assertSame(401, $this->get('/upm/-/v1/search?text=com.glitchr', 'Bearer glk_0000-0000-0000-0000-0000-0000-0000-0000')->getStatusCode());

        // The key of someone's licence of another package: known, but it does not cover this one.
        self::assertSame(403, $this->get('/upm/'.$name, 'Bearer '.$otherKey)->getStatusCode());
        self::assertSame(403, $this->get('/upm/'.$name.'/-/'.$name.'-1.0.0.tgz', 'Bearer '.$otherKey)->getStatusCode());

        // Its own key, as a Bearer: listed, described, installed.
        self::assertContains($name, $this->names($this->get('/upm/-/v1/search?text=com.glitchr', 'Bearer '.$key)));
        $document = $this->get('/upm/'.$name, 'Bearer '.$key);
        self::assertSame(200, $document->getStatusCode());
        $tarball = $this->get('/upm/'.$name.'/-/'.$name.'-1.0.0.tgz', 'Bearer '.$key);
        self::assertSame(200, $tarball->getStatusCode());
        self::assertSame(json_decode((string) $document->getContent(), true)['versions']['1.0.0']['dist']['shasum'], sha1($this->bytes($tarball, $software, '1.0.0')));
        $download = $this->em->getRepository(Download::class)->findOneBy(['channel' => 'upm', 'artifact' => $software->findRelease('1.0.0')->getArtifact('upm')]);
        self::assertSame($license->getId(), $download?->getLicense()?->getId(), 'counted against the licence');

        // As Basic e-mail:key (_auth), the same; with another e-mail, refused.
        self::assertSame(200, $this->get('/upm/'.$name, 'Basic '.base64_encode($buyer->getEmail().':'.$key))->getStatusCode());
        self::assertSame(401, $this->get('/upm/'.$name, 'Basic '.base64_encode('someone@example.org:'.$key))->getStatusCode());
    }

    /**
     * A Unity package in a repository of its own (git's command line), a tag per version, synced
     * into releases with their tarballs.
     *
     * @param list<string> $versions
     *
     * @return array{0: Software, 1: string}
     */
    private function package(Pricing $pricing, array $versions): array
    {
        if (!\is_callable('exec') || null === ($git = trim((string) shell_exec('command -v git')) ?: null)) {
            self::markTestSkipped('Needs the git command line to make the package\'s repository.');
        }
        $container = static::getContainer();
        $suffix = bin2hex(random_bytes(3));
        $name = 'com.glitchr.probe-'.$suffix;
        $repository = 'upm-probe-'.$suffix;

        $work = sys_get_temp_dir().'/forge-upm-'.$suffix;
        $bare = rtrim($container->getParameter('forge.repositories_dir'), '/').'/'.$repository.'.git';
        @mkdir($work.'/Runtime', 0777, true);
        $run = function (string $command) use ($work): void {
            exec('cd '.escapeshellarg($work).' && '.$command.' 2>&1', $output, $status);
            self::assertSame(0, $status, implode("\n", $output));
        };
        $run('git init -q -b main .');
        file_put_contents($work.'/Runtime/Probe.cs', "namespace Glitchr.Probe { public static class Probe {} }\n");
        file_put_contents($work.'/Runtime/Probe.cs.meta', "fileFormatVersion: 2\nguid: 0123456789abcdef0123456789abcdef\n");
        foreach ($versions as $version) {
            file_put_contents($work.'/CHANGELOG.md', "# Changelog\n\n## [$version]\n");
            // The tag's package.json one version behind for the last: the tarball has the release's.
            file_put_contents($work.'/package.json', json_encode(['name' => $name, 'version' => $version === end($versions) && \count($versions) > 1 ? $versions[0] : $version, 'displayName' => 'Licensing probe', 'description' => 'A probe of the Unity registry.', 'unity' => '2021.3'], JSON_PRETTY_PRINT));
            $run('git add -A && git -c user.name=Forge -c user.email=forge@example.org commit -q -m '.escapeshellarg('Version '.$version).' && git -c user.name=Forge -c user.email=forge@example.org tag -a v'.$version.' -m '.escapeshellarg('Changes of '.$version));
        }
        @mkdir(\dirname($bare), 0777, true);
        exec('git clone -q --bare '.escapeshellarg($work).' '.escapeshellarg($bare).' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        exec('rm -rf '.escapeshellarg($work));

        $studio = $this->user('studio');
        $this->em->flush();
        $software = (new Software($studio, 'Probe '.$suffix, 'upm-probe-'.$suffix))
            ->setPricing($pricing)
            ->setGitRepository($repository)
            ->setUpmName($name)
            ->publish();
        $this->em->persist($software);
        $this->em->flush();
        $container->get(RepositoryRegistry::class)->reset();

        $releases = $container->get(ReleaseSync::class)->sync($software);
        self::assertCount(\count($versions), $releases);
        foreach ($releases as $release) {
            // Tagged a moment ago: published from a second ago, so that isPublished() holds.
            $release->setPublishedAt($release->getPublishedAt()?->modify('-1 minute'));
        }
        $this->em->flush();

        return [$software, $name];
    }

    private function get(string $uri, ?string $authorization = null): Response
    {
        return $this->request('GET', $uri, $authorization ? ['HTTP_AUTHORIZATION' => $authorization] : []);
    }

    /** @return list<string> */
    private function names(Response $search): array
    {
        return array_map(fn (array $object) => $object['package']['name'], json_decode((string) $search->getContent(), true)['objects'] ?? []);
    }

    /** What a tarball response hands out: the file nginx is sent to (X-Accel-Redirect), or the file PHP streams. */
    private function bytes(Response $response, Software $software, string $version): string
    {
        $artifact = $software->findRelease($version)->getArtifact('upm');
        self::assertInstanceOf(Artifact::class, $artifact);
        if ($response->headers->has('X-Accel-Redirect')) {
            self::assertStringEndsWith('/'.$artifact->getPath(), (string) $response->headers->get('X-Accel-Redirect'));
        }

        return (string) file_get_contents(static::getContainer()->get(ArtifactBuilder::class)->absolutePath($artifact));
    }

    /** @return array<string, string> */
    private function untar(string $tgz): array
    {
        $path = sys_get_temp_dir().'/forge-upm-read-'.bin2hex(random_bytes(4)).'.tgz';
        file_put_contents($path, $tgz);
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \PharData($path)) as $file) {
            $files[substr($file->getPathname(), \strlen('phar://'.$path.'/'))] = (string) file_get_contents($file->getPathname());
        }
        @unlink($path);

        return $files;
    }
}
