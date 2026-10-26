<?php

namespace Base\Forge\Repository;

use Base\Enum\ThreadState;
use Base\Forge\Entity\Software;
use Base\Forge\Enum\SoftwareKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Software> */
class SoftwareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Software::class);
    }

    /** @return list<Software> the published ones, in the applications page's order */
    public function findVisible(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.state = :published')->setParameter('published', ThreadState::PUBLISH)
            ->orderBy('s.position', 'ASC')->addOrderBy('s.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<Software> the ones with something to download */
    public function findDownloadable(): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.releases', 'r')->addSelect('r')
            ->andWhere('s.state = :published')->setParameter('published', ThreadState::PUBLISH)
            ->andWhere('r.publishedAt IS NOT NULL AND r.publishedAt <= CURRENT_TIMESTAMP()')
            ->orderBy('s.position', 'ASC')->addOrderBy('s.id', 'ASC')->addOrderBy('r.publishedAt', 'DESC')
            ->getQuery()->getResult();
    }

    /** @return list<Software> the ones git/git-bundle can build releases for */
    public function findWithRepository(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.gitRepository IS NOT NULL')
            ->getQuery()->getResult();
    }

    /** @return list<Software> PHP packages served by the Composer repository */
    public function findPackages(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.packageName IS NOT NULL')
            ->orderBy('s.packageName', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<Software> Unity packages served by the Unity registry */
    public function findUpmPackages(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.upmName IS NOT NULL')
            ->orderBy('s.upmName', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<Software> the published games, in the applications page's order */
    public function findGames(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.state = :published')->setParameter('published', ThreadState::PUBLISH)
            ->andWhere('s.kind = :game')->setParameter('game', SoftwareKind::GAME)
            ->orderBy('s.position', 'ASC')->addOrderBy('s.id', 'ASC')
            ->getQuery()->getResult();
    }
}
