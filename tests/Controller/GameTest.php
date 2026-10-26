<?php

namespace Tests\Base\Forge\Controller;

use Base\Forge\Entity\Release;
use Base\Forge\Entity\Software;
use Base\Forge\Enum\Platform;
use Base\Forge\Enum\Pricing;
use Base\Forge\Enum\SoftwareKind;
use Base\Forge\Service\ArtifactBuilder;
use Base\Forge\Service\LicenseIssuer;
use Tests\Base\Forge\ForgeWebTestCase;

/**
 * A game on the shelf, as a visitor meets it (in a host application): its page, its WebGL build
 * played in the page with the headers Unity's compressed builds need, its builds per platform
 * downloaded through a signed link with their sha256 - and, paid, kept for its licence holders.
 */
class GameTest extends ForgeWebTestCase
{
    public function testAFreeGameIsPlayedInItsPageAndDownloadedPerPlatform(): void
    {
        [$game, $release] = $this->game(Pricing::FREE);
        $builder = static::getContainer()->get(ArtifactBuilder::class);
        $windows = $this->file('windows.zip', ['Game.exe' => 'MZ', 'Game_Data/data.unity3d' => 'data']);
        $builder->attach($release, Platform::WINDOWS, $windows, 'probe-1.0.0-windows.zip');
        $this->em->flush();

        $page = $this->request('GET', '/telechargements/'.$game->getSlug());
        self::assertSame(200, $page->getStatusCode(), substr(strip_tags((string) $page->getContent()), 0, 1500));
        $dom = $this->xpath($page);
        self::assertSame('/play/'.$game->getSlug().'/1.0.0', $dom->evaluate('string(//*[@data-forge-play-start]/@href)'), 'Play frames the build');
        self::assertSame(0, $dom->query('//*[@data-forge-play]//iframe')->length, 'nothing of the game before Play is pressed');
        self::assertSame(1, $dom->query('//script[contains(@src, "bundles/forge/js/play.js")]')->length);
        $windowsBuild = $release->getArtifact('windows');
        self::assertSame(1, $dom->query('//ul[@class="forge-builds"]//a[contains(@href, "format=windows")]')->length, 'its Windows build');
        self::assertStringContainsString($windowsBuild->getSha256(), $dom->evaluate('string(//ul[@class="forge-builds"])'), 'with its sha256');
        self::assertSame(hash_file('sha256', $windows), $windowsBuild->getSha256());

        // The build's own page, then its compressed files.
        $index = $this->request('GET', '/play/'.$game->getSlug().'/1.0.0/');
        self::assertSame(200, $index->getStatusCode());
        self::assertStringContainsString('text/html', (string) $index->headers->get('Content-Type'));
        $wasm = $this->request('GET', '/play/'.$game->getSlug().'/1.0.0/Build/probe.wasm.br');
        self::assertSame(200, $wasm->getStatusCode());
        self::assertSame('br', $wasm->headers->get('Content-Encoding'));
        self::assertSame('application/wasm', $wasm->headers->get('Content-Type'));
        self::assertSame('application/javascript', $this->request('GET', '/play/'.$game->getSlug().'/1.0.0/Build/probe.framework.js.br')->headers->get('Content-Type'));
        self::assertSame(404, $this->request('GET', '/play/'.$game->getSlug().'/1.0.0/../../../../etc/passwd')->getStatusCode(), 'nothing outside the build');
        self::assertSame(404, $this->request('GET', '/play/'.$game->getSlug().'/1.0.0/Build/missing.wasm')->getStatusCode());

        // A platform's build: through a signed, short-lived link.
        $download = $this->request('GET', '/telechargements/'.$game->getSlug().'/1.0.0?format=windows');
        self::assertSame(302, $download->getStatusCode());
        $signed = (string) $download->headers->get('Location');
        self::assertStringContainsString('_hash=', $signed);
        $file = $this->request('GET', parse_url($signed, PHP_URL_PATH).'?'.parse_url($signed, PHP_URL_QUERY));
        self::assertSame(200, $file->getStatusCode());
        self::assertSame('application/zip', $file->headers->get('Content-Type'));
        self::assertSame(404, $this->request('GET', '/telechargements/'.$game->getSlug().'/1.0.0?format=android')->getStatusCode(), 'no Android build');
    }

    public function testAPaidGameIsPlayedByItsLicenceHoldersOnly(): void
    {
        [$game, $release] = $this->game(Pricing::LICENSED);
        $player = $this->user('player');
        $this->em->flush();
        static::getContainer()->get(LicenseIssuer::class)->issue($game, $player);
        $this->em->flush();

        self::assertSame(403, $this->request('GET', '/play/'.$game->getSlug().'/1.0.0/')->getStatusCode(), 'anonymous');
        $page = $this->xpath($this->request('GET', '/telechargements/'.$game->getSlug()));
        self::assertSame(0, $page->query('//*[@data-forge-play-start]')->length, 'no Play without a licence');
        self::assertSame(1, $page->query('//*[contains(@class, "forge-play-start") and contains(@class, "is-locked")]')->length);

        $this->signIn($player);
        self::assertSame(200, $this->request('GET', '/play/'.$game->getSlug().'/1.0.0/')->getStatusCode(), 'its holder plays it');
        self::assertStringContainsString('private', (string) $this->request('GET', '/play/'.$game->getSlug().'/1.0.0/Build/probe.wasm.br')->headers->get('Cache-Control'), 'cached by their browser alone');
    }

    public function testABuildIsRefusedForThePlatformItIsNotFor(): void
    {
        [, $release] = $this->game(Pricing::FREE);
        $builder = static::getContainer()->get(ArtifactBuilder::class);

        $this->expectException(\InvalidArgumentException::class);
        $builder->attach($release, Platform::WEBGL, $this->file('not-webgl.zip', ['readme.txt' => 'no index.html']));
    }

    /** @return array{0: Software, 1: Release} a published game with a published 1.0.0 and its WebGL build */
    private function game(Pricing $pricing): array
    {
        $suffix = bin2hex(random_bytes(3));
        $studio = $this->user('studio');
        $this->em->flush();
        $game = (new Software($studio, 'Probe game '.$suffix, 'probe-game-'.$suffix))
            ->setKind(SoftwareKind::GAME)
            ->setCategory('game')
            ->setPricing($pricing)
            ->publish();
        $release = (new Release($game, '1.0.0'))->setPublishedAt(new \DateTimeImmutable('-1 hour'))->setChangelog('First playable.');
        $this->em->persist($game);

        static::getContainer()->get(ArtifactBuilder::class)->attach($release, Platform::WEBGL, $this->file('webgl.zip', [
            'probe/index.html' => '<!doctype html><title>Probe</title><canvas id="unity-canvas"></canvas><script src="Build/probe.loader.js"></script>',
            'probe/Build/probe.loader.js' => 'function createUnityInstance(){}',
            'probe/Build/probe.wasm.br' => 'compressed wasm',
            'probe/Build/probe.framework.js.br' => 'compressed framework',
            'probe/Build/probe.data.br' => 'compressed data',
            'probe/TemplateData/style.css' => 'body{}',
            '../escape.txt' => 'outside',
        ]), 'probe-1.0.0-webgl.zip');
        $this->em->flush();

        return [$game, $release];
    }

    /** @param array<string, string> $files */
    private function file(string $name, array $files): string
    {
        $path = sys_get_temp_dir().'/forge-game-'.bin2hex(random_bytes(4)).'-'.$name;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();

        return $path;
    }
}
