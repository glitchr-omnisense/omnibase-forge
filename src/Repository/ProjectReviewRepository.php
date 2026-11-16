<?php

namespace Base\Forge\Repository;

use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Parser;
use Base\Forge\Entity\ProjectReview;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method ProjectReview|null find($id, $lockMode = null, $lockVersion = null)
 * @method ProjectReview|null findOneBy(array $criteria, array $orderBy = null)
 * @method ProjectReview[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ProjectReviewRepository extends ServiceEntityRepository
{
    /** @return list<ProjectReview> the ones the studio shows, newest first */
    public function findPublished(int $limit = 12): array
    {
        return $this->findBy([
            'published' => true,
            Group::of(
                Parser::SEPARATOR_AND,
                Criterion::of('submittedAt', null, Parser::OPTION_NOT_NULL),
                // What the inner join of the project said: one that reads (none hidden by the persistence).
                Criterion::of('project.id', null, Parser::OPTION_NOT_NULL),
            ),
        ], ['submittedAt' => 'DESC'], $limit);
    }
}
