<?php

namespace Base\Forge\Service;

use Base\Forge\Entity\Artifact;
use Base\Forge\Entity\Release;
use Base\Forge\Enum\Platform;
use Git\Service\Git2Service;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Builds a release's zip straight from its git tag - every file of the tree,
 * read by git/git-bundle from the object database, under a
 * <software>-<version>/ folder. Files marked export-ignore are not filtered
 * (there is no .gitattributes evaluation here): keep the tree clean.
 *
 * For a Unity package (Software::$upmName) the same tree is also written as
 * the tarball npm and Unity's Package Manager expect (format "upm"): a gzip'd
 * ustar archive, every file under package/, its package.json naming the
 * package and the release's version. A game's or an application's builds
 * are made elsewhere (Unity, a CI) and attached to the release per platform
 * (attach()).
 */
class ArtifactBuilder
{
    public function __construct(
        private readonly Git2Service $git,
        #[Autowire('%forge.storage_dir%')] private readonly string $storageDir,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function build(Release $release): Artifact
    {
        $software = $release->getSoftware() ?? throw new \LogicException('A release without software has nothing to build.');
        $repository = $software->getGitRepository() ?? throw new \LogicException(sprintf('%s has no git repository to build from.', $software));
        $ref = $release->getTag() ?? $release->getCommitSha() ?? throw new \LogicException(sprintf('%s has neither tag nor commit.', $release));

        $folder = sprintf('%s-%s', $software->getSlug(), $release->getVersion());
        $relative = sprintf('%s/%s/%s.zip', $software->getSlug(), $release->getVersion(), $folder);
        $target = rtrim($this->storageDir, '/').'/'.$relative;
        $this->filesystem->mkdir(\dirname($target));

        $temporary = $target.'.part';
        $zip = new \ZipArchive();
        if (true !== $zip->open($temporary, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException(sprintf('Cannot write "%s".', $temporary));
        }

        $count = 0;
        foreach ($this->git->walkTree($repository, $ref) as $path => $file) {
            $name = $folder.'/'.$path;
            $zip->addFromString($name, $file['content']);
            // Keep the executable bit (100755) of scripts.
            $zip->setExternalAttributesName($name, \ZipArchive::OPSYS_UNIX, ($file['filemode'] & 0777 ?: 0644) << 16);
            ++$count;
        }
        if (0 === $count) {
            $zip->addEmptyDir($folder);
        }
        $zip->close();

        $this->filesystem->rename($temporary, $target, true);

        $this->forget($release, 'zip');

        return new Artifact($release, $relative, 'zip', (int) filesize($target), (string) hash_file('sha256', $target));
    }

    /** The release's UPM tarball, <upm name>-<version>.tgz, from the tree at its tag. Not flushed. */
    public function buildUpm(Release $release): Artifact
    {
        $software = $release->getSoftware() ?? throw new \LogicException('A release without software has nothing to build.');
        $name = $software->getUpmName() ?? throw new \LogicException(sprintf('%s has no UPM name.', $software));
        $repository = $software->getGitRepository() ?? throw new \LogicException(sprintf('%s has no git repository to build from.', $software));
        $ref = $release->getTag() ?? $release->getCommitSha() ?? throw new \LogicException(sprintf('%s has neither tag nor commit.', $release));

        $files = [];
        $manifest = false;
        foreach ($this->git->walkTree($repository, $ref) as $path => $file) {
            $content = $file['content'];
            if ('package.json' === $path) {
                $content = $this->upmManifest($content, $name, $release->getVersion());
                $manifest = true;
            }
            $files['package/'.$path] = [$content, ($file['filemode'] & 0777) ?: 0644];
        }
        if (!$manifest) {
            throw new \RuntimeException(sprintf('%s has no package.json at %s: it is not a Unity package.', $software, $ref));
        }
        ksort($files, SORT_STRING);

        $relative = sprintf('%s/%s/%s-%s.tgz', $software->getSlug(), $release->getVersion(), $name, $release->getVersion());
        $target = rtrim($this->storageDir, '/').'/'.$relative;
        $this->filesystem->mkdir(\dirname($target));
        $tarball = (string) gzencode(self::tar($files, $release->getPublishedAt()?->getTimestamp() ?? 0), 9);
        $this->filesystem->dumpFile($target, $tarball);

        $this->forget($release, 'upm');

        return new Artifact($release, $relative, 'upm', \strlen($tarball), hash('sha256', $tarball), sha1($tarball), hash('sha512', $tarball));
    }

    /**
     * A build made elsewhere (Unity, a CI) becomes the release's artifact for
     * that platform: copied into the storage, its sha256 taken, the previous
     * build for that platform replaced. Not flushed.
     */
    public function attach(Release $release, Platform $platform, string $source, ?string $filename = null): Artifact
    {
        $software = $release->getSoftware() ?? throw new \LogicException('A release without software has no builds.');
        if (!is_file($source)) {
            throw new \InvalidArgumentException(sprintf('No file at "%s".', $source));
        }
        $filename = basename($filename ?? $source);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!\in_array($extension, $platform->extensions(), true)) {
            throw new \InvalidArgumentException(sprintf('A %s build is a .%s file, not "%s".', $platform->label(), implode(' or .', $platform->extensions()), $filename));
        }
        if (Platform::WEBGL === $platform && !self::isWebglBuild($source)) {
            throw new \InvalidArgumentException(sprintf('"%s" is no WebGL build: a zip holding index.html and a Build/ folder.', $filename));
        }

        $relative = sprintf('%s/%s/%s/%s', $software->getSlug(), $release->getVersion(), $platform->value, $filename);
        $target = rtrim($this->storageDir, '/').'/'.$relative;
        // The platform's folder: its previous build, and what was unpacked of it (WebglBuilds).
        $this->filesystem->remove(\dirname($target));
        $this->filesystem->mkdir(\dirname($target));
        $this->filesystem->copy($source, $target, true);

        $this->forget($release, $platform->value);

        return new Artifact($release, $relative, $platform->value, (int) filesize($target), (string) hash_file('sha256', $target));
    }

    /** A zip with an index.html and a Build/ folder at its root, or under one folder. */
    public static function isWebglBuild(string $zipFile): bool
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($zipFile, \ZipArchive::RDONLY)) {
            return false;
        }
        try {
            return null !== self::webglRoot($zip);
        } finally {
            $zip->close();
        }
    }

    /** The folder of a WebGL build's zip that holds its index.html and Build/ ("" at the root), or null. */
    public static function webglRoot(\ZipArchive $zip): ?string
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        foreach ($names as $name) {
            if (preg_match('~^((?:[^/]+/)?)index\.html$~', $name, $match)) {
                foreach ($names as $other) {
                    if (str_starts_with($other, $match[1].'Build/')) {
                        return $match[1];
                    }
                }
            }
        }

        return null;
    }

    public function absolutePath(Artifact $artifact): string
    {
        return rtrim($this->storageDir, '/').'/'.$artifact->getPath();
    }

    /** The release's artifact of that format taken off: the new one replaces it. */
    private function forget(Release $release, string $format): void
    {
        foreach ($release->getArtifacts() as $previous) {
            if ($format === $previous->getFormat()) {
                $release->getArtifacts()->removeElement($previous);
            }
        }
    }

    /** The package.json as the tarball carries it: the package's name and the release's version, whatever the tag had. */
    private function upmManifest(string $json, string $name, string $version): string
    {
        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['name'] ?? null) === $name && ($manifest['version'] ?? null) === $version) {
            return $json;
        }
        $manifest = ['name' => $name, 'version' => $version] + $manifest;

        return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * A ustar archive of those files (path => [content, mode]), every entry
     * dated $mtime: the same tree makes the same bytes, so the same shasum.
     *
     * @param array<string, array{0: string, 1: int}> $files
     */
    public static function tar(array $files, int $mtime = 0): string
    {
        $tar = '';
        foreach ($files as $path => [$content, $mode]) {
            [$prefix, $name] = ['', $path];
            if (\strlen($path) > 100) {
                $cut = strrpos(substr($path, 0, 156), '/');
                if (false === $cut || \strlen(substr($path, $cut + 1)) > 100) {
                    throw new \RuntimeException(sprintf('"%s" is too long a path for a ustar archive.', $path));
                }
                [$prefix, $name] = [substr($path, 0, $cut), substr($path, $cut + 1)];
            }
            $header = pack('a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
                $name, sprintf('%07o', $mode & 0777), sprintf('%07o', 0), sprintf('%07o', 0),
                sprintf('%011o', \strlen($content)), sprintf('%011o', $mtime), str_repeat(' ', 8), '0', '',
                "ustar\0", '00', '', '', '', '', $prefix, '');
            $checksum = array_sum(array_map('ord', str_split($header)));
            $header = substr_replace($header, sprintf('%06o', $checksum)."\0 ", 148, 8);
            $tar .= $header.$content.str_repeat("\0", (512 - \strlen($content) % 512) % 512);
        }

        return $tar.str_repeat("\0", 1024);
    }
}
