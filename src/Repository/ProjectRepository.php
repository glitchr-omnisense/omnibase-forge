<?php

namespace Base\Forge\Repository;

use Base\Entity\User;
use Base\Forge\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Project> */
class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /** @return list<Project> open ones first, then by name */
    public function findForClient(User $client): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.client = :client')->setParameter('client', $client)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()->getResult();
    }

    /** @return list<Project> every project with a git repository */
    public function findWithRepository(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.gitRepository IS NOT NULL')
            ->getQuery()->getResult();
    }

    /** @return list<Project> the projects whose repository lives somewhere: where their runs are read (Service\PipelineSync) */
    public function findWithRepositoryUrl(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.repositoryUrl IS NOT NULL')
            ->orderBy('p.id', 'ASC')
            ->getQuery()->getResult();
    }

    public function findOneByRepository(string $repository): ?Project
    {
        return $this->findOneBy(['gitRepository' => $repository]);
    }
}
