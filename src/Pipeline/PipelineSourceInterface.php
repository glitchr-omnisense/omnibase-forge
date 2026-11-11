<?php

namespace Base\Forge\Pipeline;

use Base\Forge\Entity\Project;
use Base\Forge\Model\PipelineRun;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Where a project's runs are read: the CI of the host its repository lives on. A source reads
 * and says, nothing more; Service\PipelineSync records what it says. One per host - GitHub
 * Actions today (GithubActionsSource); a GitLab CI or a Forgejo Actions would be one class more,
 * tagged by itself.
 */
#[AutoconfigureTag(self::TAG)]
interface PipelineSourceInterface
{
    public const TAG = 'forge.pipeline_source';

    /** Whether the project's repository lives on this host (and the source can read it now). */
    public function supports(Project $project): bool;

    /**
     * The latest runs, newest first.
     *
     * @return list<PipelineRun>
     *
     * @throws \RuntimeException when the host does not answer as it should
     */
    public function runs(Project $project, int $limit = 10): array;
}
