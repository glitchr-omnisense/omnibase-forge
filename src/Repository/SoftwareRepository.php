<?php

namespace Base\Forge\Repository;

use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Parser;
use Base\Enum\ThreadState;
use Base\Forge\Entity\Software;
use Base\Forge\Enum\SoftwareKind;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method Software|null find($id, $lockMode = null, $lockVersion = null)
 * @method Software|null findOneBy(array $criteria, array $orderBy = null)
 * @method Software[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SoftwareRepository extends ServiceEntityRepository
{
    /** @return list<Software> the published ones, in the applications page's order */
    public function findVisible(): array
    {
        return $this->findBy(['state' => ThreadState::PUBLISH], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * The published ones with a release out (published at or before now), in
     * the applications page's order. Their releases are read through the
     * collection, newest first (its mapping's order); the templates keep the
     * published ones (Software::getLatestRelease(), Release::isPublished()).
     *
     * @return list<Software>
     */
    public function findDownloadable(): array
    {
        return $this->findBy([
            'state' => ThreadState::PUBLISH,
            Group::of(Parser::SEPARATOR_AND, Criterion::of('releases.publishedAt', new \DateTimeImmutable(), Parser::OPTION_LOWER_EQUAL)),
        ], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /** @return list<Software> the ones git/git-bundle can build releases for */
    public function findWithRepository(): array
    {
        return $this->findBy([self::notNull('gitRepository')]);
    }

    /** @return list<Software> PHP packages served by the Composer repository */
    public function findPackages(): array
    {
        return $this->findBy([self::notNull('packageName')], ['packageName' => 'ASC']);
    }

    /** @return list<Software> Unity packages served by the Unity registry */
    public function findUpmPackages(): array
    {
        return $this->findBy([self::notNull('upmName')], ['upmName' => 'ASC']);
    }

    /** @return list<Software> the published games, in the applications page's order */
    public function findGames(): array
    {
        return $this->findBy(['state' => ThreadState::PUBLISH, 'kind' => SoftwareKind::GAME], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /** The criterion "$field IS NOT NULL". */
    private static function notNull(string $field): Group
    {
        return Group::of(Parser::SEPARATOR_AND, Criterion::of($field, null, Parser::OPTION_NOT_NULL));
    }
}
