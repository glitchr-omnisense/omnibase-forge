<?php

namespace Base\Forge\Model;

use Base\Forge\Enum\PipelineStatus;

/**
 * One run of a project's checks as the host that ran it tells it (a PipelineSourceInterface's
 * answer): what Service\PipelineSync writes into a Pipeline, its stages and its jobs. No entity,
 * no storage: a source only reads.
 */
final class PipelineRun
{
    /**
     * @param list<PipelineRunJob> $jobs in order
     * @param ?PipelineStatus      $status set only when the run's own word overrides its jobs' (canceled)
     */
    public function __construct(
        public readonly string $source,
        public readonly string $externalId,
        public readonly ?string $name = null,
        public readonly ?string $ref = null,
        public readonly ?string $commitSha = null,
        public readonly ?string $url = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $finishedAt = null,
        public readonly ?PipelineStatus $status = null,
        public readonly array $jobs = [],
    ) {
    }
}
