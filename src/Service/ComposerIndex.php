<?php

namespace Base\Forge\Service;

use Base\Service\DownloadLinks;
use Base\Forge\Entity\License;
use Base\Forge\Entity\Release;
use Base\Forge\Entity\Software;
use Base\Forge\Repository\SoftwareRepository;
use Git\Service\Git2Service;
use Psr\Log\LoggerInterface;

/**
 * The studio's own Packagist: the index (packages.json) of a Composer repository of the
 * PHP packages among the software. Free ones are listed for anyone; licensed
 * ones for the holder of a valid licence, each version its licence covers,
 * with a dist URL signed for that licence.
 *
 *     composer config repositories.glitchr composer https://glitchr.io/composer
 *     composer config http-basic.glitchr.io <email> <licence key>
 *     composer require glitchr/<package>
 *
 * Each version's metadata is its own composer.json at that tag, read through
 * git/git-bundle, with name, version, dist and source set here.
 */
class ComposerIndex
{
    public function __construct(
        private readonly SoftwareRepository $software,
        private readonly LicenseHolders $holders,
        private readonly DownloadLinks $links,
        private readonly Git2Service $git,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param list<License> $licenses the caller's valid licences (empty: anonymous)
     *
     * @return array{packages: array<string, array<string, array>>}
     */
    public function packages(array $licenses = []): array
    {
        $bySoftware = [];
        foreach ($licenses as $license) {
            if ($license->isValid()) {
                $bySoftware[(int) $license->getSoftware()?->getId()][] = $license;
            }
        }

        $packages = [];
        foreach ($this->software->findPackages() as $software) {
            $held = $bySoftware[$software->getId()] ?? [];
            if (!$software->isFree() && !$held) {
                continue;
            }

            foreach ($software->getReleases() as $release) {
                $license = $software->isFree() ? null : $this->covering($held, $release);
                if (!$release->isPublished() || !$release->getArtifact('zip') || (!$software->isFree() && !$license)) {
                    continue;
                }
                if ($package = $this->package($software, $release, $license)) {
                    $packages[$software->getPackageName()][$release->getVersion()] = $package;
                }
            }
        }

        return ['packages' => $packages];
    }

    /**
     * Who is behind a Basic auth pair (e-mail, key): the seat that key was
     * made for, given to that e-mail, on a valid licence - and then every
     * valid licence with a seat for that e-mail.
     *
     * @return array{0: ?string, 1: list<License>} the e-mail, or null when the pair is wrong
     */
    public function authenticate(?string $email, ?string $key): array
    {
        return $this->holders->authenticate($key, $email, true);
    }

    /** @param list<License> $licenses */
    private function covering(array $licenses, Release $release): ?License
    {
        foreach ($licenses as $license) {
            if ($license->covers($release)) {
                return $license;
            }
        }

        return null;
    }

    private function package(Software $software, Release $release, ?License $license): ?array
    {
        $manifest = [];
        try {
            $blob = $this->git->getBlob((string) $software->getGitRepository(), (string) ($release->getTag() ?? $release->getCommitSha()), 'composer.json');
            $manifest = json_decode($blob['content'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger?->warning('No composer.json for {software} {version}: {error}', ['software' => $software->getName(), 'version' => $release->getVersion(), 'error' => $e->getMessage()]);
        }

        $artifact = $release->getArtifact('zip');
        // Composer caches dist files by URL and reference: a long-lived
        // signature keeps a cached install valid while the licence is.
        $url = $this->links->sign('forge_download_file', ['id' => $artifact->getId(), 'filename' => $artifact->getFilename()] + array_filter(['license' => $license?->getId(), 'channel' => 'composer']), 60 * 60 * 24 * 30);

        return array_merge(
            \is_array($manifest) ? $manifest : [],
            [
                'name' => $software->getPackageName(),
                'version' => $release->getVersion(),
                'dist' => ['type' => 'zip', 'url' => $url, 'reference' => $release->getCommitSha()],
                'time' => $release->getPublishedAt()?->format(DATE_ATOM),
            ],
            $software->isFree() && $software->getSourceUrl() ? ['source' => ['type' => 'git', 'url' => $software->getSourceUrl(), 'reference' => $release->getCommitSha()]] : [],
        );
    }
}
