<?php

namespace Base\Forge\Service;

use Base\Forge\Entity\Artifact;
use Base\Forge\Entity\License;
use Base\Forge\Entity\Release;
use Base\Forge\Entity\Software;
use Base\Forge\Repository\SoftwareRepository;
use Git\Service\Git2Service;
use Omnihub\Package;
use Omnihub\Upm\Registry;
use Omnihub\Version;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The studio's Unity registry: the npm registry protocol Unity's Package
 * Manager reads from a scoped registry, over the Unity packages among the
 * software (Software::$upmName) - ComposerIndex's sibling. Free packages for
 * anyone; licensed ones for the holder of a valid licence, each version its
 * licence covers.
 *
 *     Packages/manifest.json
 *         "scopedRegistries": [{"name": "Glitch Art", "url": "https://glitchr.io/upm", "scopes": ["com.glitchr"]}]
 *     ~/.upmconfig.toml (a licensed package)
 *         [npmAuth."https://glitchr.io/upm"]
 *         token = "<licence key>"
 *         alwaysAuth = true
 *
 * What the Package Manager asks, as seen from Unity 6 (2026-10-26): the
 * search, GET /-/v1/search?text=<scope>&from=0&size=250, page after page
 * until one comes back empty (GET /-/all when the search is a 404); the
 * package document, GET /<name>; then HEAD and GET of the version's
 * dist.tarball - every request with the token as "Authorization: Bearer"
 * (or the Basic pair of `_auth`).
 *
 * Each version's metadata is its package.json at that tag, read through
 * git/git-bundle, with its name and version set here - as the tarball has
 * them (ArtifactBuilder::buildUpm()).
 *
 * The documents themselves are omnisense/omnihub's (Omnihub\Upm\Registry),
 * written once for a forge and a static hub alike: this registry hands it an
 * Omnihub\Package holding only the versions the caller may install. The
 * search is still written here, until omnihub's takes a publisher (docs:
 * omnihub's needs, docs/upm.md).
 */
class UpmRegistry
{
    public function __construct(
        private readonly SoftwareRepository $software,
        private readonly LicenseHolders $holders,
        private readonly UrlGeneratorInterface $urls,
        private readonly Git2Service $git,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Who the Authorization header is: [null, []] without one (anonymous),
     * null when it is wrong (a 401, so that Unity says so rather than
     * resolving without the licensed packages).
     *
     * @return array{0: ?string, 1: list<License>}|null
     */
    public function authenticate(?string $authorization): ?array
    {
        $credentials = LicenseHolders::fromHeader($authorization);
        if (null === $credentials) {
            return [null, []];
        }
        [$key, $email] = $credentials;
        [$holder, $licenses] = $this->holders->authenticate($key, $email);

        return $holder ? [$holder, $licenses] : null;
    }

    /**
     * The registry's address, as Unity's manifest names it (/upm, with no package): the
     * registry's identity in the hub manifest and in the licence tokens it binds (LicenseToken).
     */
    public function url(): string
    {
        return rtrim(\dirname($this->urls->generate('forge_upm_package', ['name' => 'x.x'], UrlGeneratorInterface::ABSOLUTE_URL)), '/');
    }

    public function find(string $name): ?Software
    {
        return $this->software->findOneBy(['upmName' => $name]);
    }

    /**
     * The package document (npm's "packument"): the versions the caller may
     * install, newest first in dist-tags.latest. Empty versions: none for
     * them (the controller tells a 401 from a 403 from a 404).
     *
     * @param list<License> $licenses the caller's valid licences
     */
    public function document(Software $software, array $licenses = []): array
    {
        $versions = [];
        foreach ($this->installable($software, $licenses) as [$release, $artifact]) {
            $versions[] = $this->version($software, $release, $artifact, $this->manifest($software, $release));
        }

        return Registry::document(new Package((string) $software->getUpmName(), $versions, $software->getHomepage()), $software->getTagline());
    }

    /**
     * The search: the packages whose name, display name, description or
     * keywords hold every word of the text (Unity sends the scope), one page.
     *
     * @param list<License> $licenses
     */
    public function search(string $text, int $from = 0, int $size = 20, array $licenses = []): array
    {
        $words = array_filter(preg_split('/\s+/', mb_strtolower(trim($text))) ?: []);
        $objects = [];
        foreach ($this->listing($licenses) as $package) {
            $latest = $package->latest();
            $keywords = \is_array($package->field('keywords')) ? array_values($package->field('keywords')) : null;
            $haystack = mb_strtolower(implode(' ', [$package->name, $package->field('displayName', ''), $package->field('description', ''), implode(' ', $keywords ?? [])]));
            foreach ($words as $word) {
                if (!str_contains($haystack, $word)) {
                    continue 2;
                }
            }
            $objects[] = [
                'package' => array_filter([
                    'name' => $package->name,
                    'displayName' => $package->field('displayName'),
                    'version' => $latest->version,
                    'description' => $package->field('description'),
                    'keywords' => $keywords,
                    'date' => self::time($latest->publishedAt),
                    'links' => array_filter(['homepage' => $package->homepage, 'repository' => $package->repository]) ?: new \ArrayObject(),
                    'publisher' => ['username' => 'glitchr'],
                    'maintainers' => [],
                ], fn ($value) => null !== $value),
                'score' => ['final' => 1, 'detail' => ['quality' => 1, 'popularity' => 1, 'maintenance' => 1]],
                'searchScore' => 1,
            ];
        }

        return [
            'objects' => \array_slice($objects, max(0, $from), max(1, min(250, $size))),
            'total' => \count($objects),
            'time' => gmdate('D M d Y H:i:s \G\M\TO'),
        ];
    }

    /**
     * The older listing of every package, which the Package Manager reads
     * when the search is not there.
     *
     * @param list<License> $licenses
     */
    public function all(array $licenses = []): array
    {
        return Registry::all($this->listing($licenses));
    }

    /**
     * The tarball of that file name, when the caller may have it.
     *
     * @param list<License> $licenses
     *
     * @return array{0: Artifact, 1: ?License}|null
     */
    public function tarball(Software $software, string $filename, array $licenses = []): ?array
    {
        foreach ($this->installable($software, $licenses) as [$release, $artifact, $license]) {
            if ($filename === $software->getUpmName().'-'.$release->getVersion().'.tgz') {
                return [$artifact, $license];
            }
        }

        return null;
    }

    /** The published release that tarball is of, whoever may have it (a 401/403 rather than a 404). */
    public function release(Software $software, string $filename): ?Release
    {
        foreach ($software->getReleases() as $release) {
            if ($release->isPublished() && $release->getArtifact('upm') && $filename === $software->getUpmName().'-'.$release->getVersion().'.tgz') {
                return $release;
            }
        }

        return null;
    }

    /** Whether it has versions to install at all, for someone (a 401/403 rather than a 404). */
    public function hasVersions(Software $software): bool
    {
        foreach ($software->getReleases() as $release) {
            if ($release->isPublished() && $release->getArtifact('upm')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<License> $licenses
     *
     * @return \Generator<array{0: Release, 1: Artifact, 2: ?License}> the published releases with a tarball the caller may install
     */
    private function installable(Software $software, array $licenses): \Generator
    {
        $held = array_values(array_filter($licenses, fn (License $license) => $license->isValid() && $license->getSoftware()?->getId() === $software->getId()));
        if (!$software->isFree() && !$held) {
            return;
        }
        foreach ($software->getReleases() as $release) {
            $artifact = $release->getArtifact('upm');
            if (!$release->isPublished() || !$artifact) {
                continue;
            }
            $license = null;
            if (!$software->isFree()) {
                foreach ($held as $candidate) {
                    if ($candidate->covers($release)) {
                        $license = $candidate;
                        break;
                    }
                }
                if (!$license) {
                    continue;
                }
            }
            yield [$release, $artifact, $license];
        }
    }

    /**
     * Every Unity package with a version the caller may install, as the search and the listing
     * show it: its newest such version only (one package.json read a package), whose manifest
     * carries the forge's defaults - the software's name for a display name, its tagline for a
     * description, an empty one left out.
     *
     * @param list<License> $licenses
     *
     * @return list<Package>
     */
    private function listing(array $licenses): array
    {
        $packages = [];
        foreach ($this->software->findUpmPackages() as $software) {
            $installable = [];
            foreach ($this->installable($software, $licenses) as [$release, $artifact]) {
                $installable[$release->getVersion()] = [$release, $artifact];
            }
            $newest = (new Package((string) $software->getUpmName(), array_map(fn (array $found) => $this->version($software, $found[0], $found[1], []), $installable)))->latest();
            if (!$newest) {
                continue;
            }
            [$release, $artifact] = $installable[$newest->version];
            $manifest = $this->manifest($software, $release);
            foreach (['displayName' => $software->getName(), 'description' => $software->getTagline()] as $key => $default) {
                $manifest[$key] ??= $default;
                if (null === $manifest[$key] || '' === $manifest[$key]) {
                    unset($manifest[$key]);
                }
            }
            $packages[] = new Package((string) $software->getUpmName(), [$this->version($software, $release, $artifact, $manifest)], $software->getHomepage(), $software->getSourceUrl());
        }

        return $packages;
    }

    /** @param array<string, mixed> $manifest */
    private function version(Software $software, Release $release, Artifact $artifact, array $manifest): Version
    {
        return new Version($release->getVersion(), $manifest, $this->tarballUrl($software, $release), (string) $artifact->getSha1(), $artifact->getSha512(), $release->getPublishedAt(), $release->getChangelog());
    }

    /** Its package.json at that tag, its name and version the release's. */
    private function manifest(Software $software, Release $release): array
    {
        $manifest = [];
        try {
            $blob = $this->git->getBlob((string) $software->getGitRepository(), (string) ($release->getTag() ?? $release->getCommitSha()), 'package.json');
            $manifest = json_decode($blob['content'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger?->warning('No package.json for {software} {version}: {error}', ['software' => $software->getName(), 'version' => $release->getVersion(), 'error' => $e->getMessage()]);
        }

        return array_merge(\is_array($manifest) ? $manifest : [], ['name' => $software->getUpmName(), 'version' => $release->getVersion()]);
    }

    private function tarballUrl(Software $software, Release $release): string
    {
        $name = (string) $software->getUpmName();

        return $this->urls->generate('forge_upm_tarball', ['name' => $name, 'filename' => $name.'-'.$release->getVersion().'.tgz'], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private static function time(?\DateTimeInterface $at): string
    {
        return ($at ? \DateTimeImmutable::createFromInterface($at) : new \DateTimeImmutable('@0'))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z');
    }
}
