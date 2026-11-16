<?php

namespace Base\Forge\Repository;

use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Entity\User;
use Base\Forge\Entity\Project;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method Project|null find($id, $lockMode = null, $lockVersion = null)
 * @method Project|null findOneBy(array $criteria, array $orderBy = null)
 * @method Project[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ProjectRepository extends ServiceEntityRepository
{
    /** @return list<Project> open ones first, then by name */
    public function findForClient(User $client): array
    {
        return $this->findBy(['client' => Key::of($client)], ['createdAt' => 'DESC']);
    }

    /** @return list<Project> every project with a git repository */
    public function findWithRepository(): array
    {
        return $this->findBy([self::notNull('gitRepository')]);
    }

    /** @return list<Project> the projects whose repository lives somewhere: where their runs are read (Service\PipelineSync) */
    public function findWithRepositoryUrl(): array
    {
        return $this->findBy([self::notNull('repositoryUrl')], ['id' => 'ASC']);
    }

    public function findOneByRepository(string $repository): ?Project
    {
        return $this->findOneBy(['gitRepository' => $repository]);
    }

    /** The criterion "$field IS NOT NULL". */
    private static function notNull(string $field): Group
    {
        return Group::of(Parser::SEPARATOR_AND, Criterion::of($field, null, Parser::OPTION_NOT_NULL));
    }
}
