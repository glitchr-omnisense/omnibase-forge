<?php

namespace Base\Forge\Service;

use Base\Forge\Entity\Release;
use Base\Forge\Entity\Software;
use Doctrine\ORM\EntityManagerInterface;
use Git\Service\Git2Service;

/**
 * Tags become releases: for each software with a repository, every tag that
 * reads as a version (v1.2.3, 1.2, 2.0.0-beta1) and has no release yet gets
 * one - its commit, its message as changelog, its date as publication - and
 * its zip, and its UPM tarball when the software is a Unity package.
 */
class ReleaseSync
{
    public const VERSION = '/^v?(\d+(?:\.\d+){0,3}(?:[-.]?(?:alpha|beta|rc|patch|p)\.?\d*)?)$/i';

    public function __construct(
        private readonly Git2Service $git,
        private readonly ArtifactBuilder $builder,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return list<Release> the releases created, flushed */
    public function sync(Software $software, bool $build = true): array
    {
        $repository = $software->getGitRepository();
        if (!$repository || !$this->git->hasRepository($repository)) {
            return [];
        }

        $created = [];
        foreach ($this->git->getTags($repository) as $name => $tag) {
            if (!preg_match(self::VERSION, $name, $match) || $software->findRelease($match[1])) {
                continue;
            }

            $release = new Release($software, $match[1]);
            try {
                $details = $this->git->getTag($repository, $name);
                $release->setTag($name);
                $release->setCommitSha($details['sha']);
                $release->setChangelog($details['message']);
                $release->setPublishedAt($details['date']);

                if ($build) {
                    $this->buildArchives($release);
                }
            } catch (\Throwable $e) {
                // Attached to its software by its constructor: left there, the
                // run's next flush (the next software's) cascaded in a release
                // without an archive - skipped by findRelease() for ever, 404 to
                // download.
                $software->removeRelease($release);

                throw $e;
            }

            $this->entityManager->persist($release);
            $created[] = $release;
        }

        $this->entityManager->flush();

        return $created;
    }

    /** Rebuild one release's archives (a tag moved, the storage was lost). Flushed. */
    public function rebuild(Release $release): void
    {
        $this->buildArchives($release);
        $this->entityManager->flush();
    }

    /** What is built from the tree at its tag: the zip, and the UPM tarball of a Unity package. */
    private function buildArchives(Release $release): void
    {
        $this->builder->build($release);
        if ($release->getSoftware()?->getUpmName()) {
            $this->builder->buildUpm($release);
        }
    }
}
