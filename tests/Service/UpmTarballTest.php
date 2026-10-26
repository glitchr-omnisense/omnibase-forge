<?php

namespace Tests\Base\Forge\Service;

use Base\Forge\Service\ArtifactBuilder;
use Base\Forge\Service\LicenseHolders;
use Base\Forge\Service\WebglBuilds;
use PHPUnit\Framework\TestCase;

/**
 * The pieces of the Unity registry and of the games that need no kernel: the ustar archive
 * the UPM tarball is (read back by PHP's own tar reader), the credentials a package manager
 * sends, and the headers a WebGL build's files are served with.
 */
class UpmTarballTest extends TestCase
{
    public function testTheTarballIsAUstarArchiveOfThePackageFolder(): void
    {
        $long = 'package/Runtime/'.str_repeat('Deep/', 22).'Licensing.cs';
        self::assertGreaterThan(100, \strlen($long));
        $files = [
            'package/package.json' => ['{"name": "com.glitchr.licensing", "version": "1.0.0"}', 0644],
            'package/Runtime/Licensing.cs' => ['class Licensing {}', 0644],
            $long => [str_repeat('x', 700), 0755],
        ];
        $tar = ArtifactBuilder::tar($files, 1792972800);
        self::assertSame(0, \strlen($tar) % 512, 'whole blocks');
        self::assertSame($tar, ArtifactBuilder::tar($files, 1792972800), 'the same tree, the same bytes: the same shasum');

        $path = sys_get_temp_dir().'/forge-upm-'.bin2hex(random_bytes(4)).'.tgz';
        file_put_contents($path, gzencode($tar, 9));
        try {
            $read = [];
            foreach (new \RecursiveIteratorIterator(new \PharData($path)) as $file) {
                $read[substr($file->getPathname(), \strlen('phar://'.$path.'/'))] = file_get_contents($file->getPathname());
            }
            ksort($read);
            $expected = array_map(fn (array $file) => $file[0], $files);
            ksort($expected);
            self::assertSame($expected, $read);
        } finally {
            @unlink($path);
        }
    }

    public function testTheCredentialsOfAnAuthorizationHeader(): void
    {
        self::assertNull(LicenseHolders::fromHeader(null), 'anonymous');
        self::assertSame(['glk_KEY', null], LicenseHolders::fromHeader('Bearer glk_KEY'), 'token in .upmconfig.toml');
        self::assertSame(['glk_KEY', 'ada@example.org'], LicenseHolders::fromHeader('Basic '.base64_encode('ada@example.org:glk_KEY')), '_auth in .upmconfig.toml, or Composer');
        self::assertSame([null, null], LicenseHolders::fromHeader('Digest whatever'), 'sent, but nothing to read: refused');
    }

    public function testAWebglBuildsFilesAreServedAsTheLoaderExpects(): void
    {
        self::assertSame(['Content-Encoding' => 'br', 'Content-Type' => 'application/wasm'], WebglBuilds::headers('Build/game.wasm.br'));
        self::assertSame(['Content-Encoding' => 'gzip', 'Content-Type' => 'application/javascript'], WebglBuilds::headers('Build/game.framework.js.gz'));
        self::assertSame(['Content-Encoding' => 'br', 'Content-Type' => 'application/octet-stream'], WebglBuilds::headers('Build/game.data.br'));
        self::assertSame(['Content-Type' => 'application/octet-stream'], WebglBuilds::headers('Build/game.wasm.unityweb'), 'a decompression fallback build: its loader decompresses');
        self::assertSame(['Content-Type' => 'application/javascript'], WebglBuilds::headers('Build/game.loader.js'));
        self::assertSame(['Content-Type' => 'text/html; charset=UTF-8'], WebglBuilds::headers('index.html'));
    }
}
