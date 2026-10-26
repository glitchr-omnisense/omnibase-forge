<?php

namespace Base\Forge\Service;

use Base\Forge\Entity\Artifact;
use Base\Forge\Entity\License;
use Base\Forge\Entity\Release;
use Base\Forge\Entity\Software;
use Base\Forge\Repository\SoftwareRepository;
use Git\Service\Git2Service;
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
        $versions = $times = [];
        foreach ($this->installable($software, $licenses) as [$release, $artifact]) {
            $manifest = $this->manifest($software, $release);
            $versions[$release->getVersion()] = array_merge($manifest, [
                'name' => $software->getUpmName(),
                'version' => $release->getVersion(),
                '_id' => $software->getUpmName().'@'.$release->getVersion(),
                'dist' => array_filter([
                    'tarball' => $this->tarballUrl($software, $release),
                    'shasum' => $artifact->getSha1(),
                    'integrity' => $artifact->getIntegrity(),
                ]),
            ]);
            $times[$release->getVersion()] = self::time($release->getPublishedAt());
        }

        // Oldest first, as npm writes them.
        uksort($versions, 'version_compare');
        uksort($times, 'version_compare');
        $latest = self::latest(array_keys($versions));
        $head = $latest ? $versions[$latest] : [];
        $published = array_filter(array_map(fn ($time) => strtotime($time), $times));

        return array_filter([
            '_id' => $software->getUpmName(),
            'name' => $software->getUpmName(),
            'description' => $head['description'] ?? $software->getTagline(),
            'dist-tags' => $latest ? ['latest' => $latest] : new \ArrayObject(),
            'versions' => $versions ?: new \ArrayObject(),
            'time' => $times + ($published ? ['created' => gmdate('Y-m-d\TH:i:s.000\Z', min($published)), 'modified' => gmdate('Y-m-d\TH:i:s.000\Z', max($published))] : []),
            'keywords' => $head['keywords'] ?? null,
            'homepage' => $software->getHomepage(),
            'license' => $head['license'] ?? null,
        ], fn ($value) => null !== $value);
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
        foreach ($this->summaries($licenses) as $summary) {
            $haystack = mb_strtolower(implode(' ', [$summary['name'], $summary['displayName'] ?? '', $summary['description'] ?? '', implode(' ', $summary['keywords'] ?? [])]));
            foreach ($words as $word) {
                if (!str_contains($haystack, $word)) {
                    continue 2;
                }
            }
            $objects[] = [
                'package' => array_filter([
                    'name' => $summary['name'],
                    'displayName' => $summary['displayName'] ?? null,
                    'version' => $summary['version'],
                    'description' => $summary['description'] ?? null,
                    'keywords' => $summary['keywords'] ?? null,
                    'date' => $summary['date'],
                    'links' => array_filter(['homepage' => $summary['homepage'] ?? null, 'repository' => $summary['repository'] ?? null]) ?: new \ArrayObject(),
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
        $all = ['_updated' => time()];
        foreach ($this->summaries($licenses) as $summary) {
            $all[$summary['name']] = array_filter([
                'name' => $summary['name'],
                'description' => $summary['description'] ?? null,
                'dist-tags' => ['latest' => $summary['version']],
                'versions' => [$summary['version'] => 'latest'],
                'time' => ['modified' => $summary['date']],
                'keywords' => $summary['keywords'] ?? null,
            ], fn ($value) => null !== $value);
        }

        return $all;
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
     * @param list<License> $licenses
     *
     * @return list<array{name: string, version: string, date: string, displayName?: string, description?: string, keywords?: list<string>, homepage?: string, repository?: string}>
     */
    private function summaries(array $licenses): array
    {
        $summaries = [];
        foreach ($this->software->findUpmPackages() as $software) {
            $releases = [];
            foreach ($this->installable($software, $licenses) as [$release]) {
                $releases[$release->getVersion()] = $release;
            }
            if (!$latest = self::latest(array_keys($releases))) {
                continue;
            }
            $manifest = $this->manifest($software, $releases[$latest]);
            $summaries[] = array_filter([
                'name' => (string) $software->getUpmName(),
                'version' => $latest,
                'date' => self::time($releases[$latest]->getPublishedAt()),
                'displayName' => $manifest['displayName'] ?? $software->getName(),
                'description' => $manifest['description'] ?? $software->getTagline(),
                'keywords' => isset($manifest['keywords']) && \is_array($manifest['keywords']) ? array_values($manifest['keywords']) : null,
                'homepage' => $software->getHomepage(),
                'repository' => $software->getSourceUrl(),
            ], fn ($value) => null !== $value && '' !== $value);
        }

        return $summaries;
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

    /** @param list<string> $versions the newest: the newest stable one, else the newest pre-release */
    private static function latest(array $versions): ?string
    {
        $stable = array_filter($versions, fn (string $version) => !preg_match('/[a-z]/i', $version));
        $pool = $stable ?: $versions;
        usort($pool, 'version_compare');

        return $pool ? (string) end($pool) : null;
    }

    private static function time(?\DateTimeInterface $at): string
    {
        return ($at ? \DateTimeImmutable::createFromInterface($at) : new \DateTimeImmutable('@0'))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z');
    }
}
