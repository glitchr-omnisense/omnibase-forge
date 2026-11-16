<?php

namespace Base\Forge\Repository;

use Base\Database\Repository\Finder\Key;
use Base\Forge\Entity\Pipeline;
use Base\Forge\Entity\Project;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method Pipeline|null find($id, $lockMode = null, $lockVersion = null)
 * @method Pipeline|null findOneBy(array $criteria, array $orderBy = null)
 * @method Pipeline[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PipelineRepository extends ServiceEntityRepository
{
    /** @return list<Pipeline> a project's latest runs, newest first */
    public function findLatestFor(Project $project, int $limit = 5): array
    {
        return $this->findBy(['project' => Key::of($project)], ['createdAt' => 'DESC', 'id' => 'DESC'], $limit);
    }

    /** @return list<Pipeline> the latest runs of every project, newest first */
    public function findLatest(int $limit = 30): array
    {
        return $this->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC'], $limit);
    }

    /** The run a source calls by that id: the one to bring up to date rather than record twice. */
    public function findOneBySource(string $source, string $externalId): ?Pipeline
    {
        return $this->findOneBy(['source' => $source, 'externalId' => $externalId]);
    }
}
