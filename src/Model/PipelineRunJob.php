<?php

namespace Base\Forge\Model;

use Base\Forge\Enum\PipelineStatus;

/** A job of a PipelineRun: its stage by name, what it waits for by name, where its log is. */
final class PipelineRunJob
{
    /** @param list<string> $needs the jobs it waits for, by name; empty: every job of the stage before */
    public function __construct(
        public readonly string $name,
        public readonly string $stage,
        public readonly PipelineStatus $status,
        public readonly array $needs = [],
        public readonly ?string $url = null,
        public readonly bool $allowFailure = false,
        public readonly ?\DateTimeImmutable $startedAt = null,
        public readonly ?\DateTimeImmutable $finishedAt = null,
    ) {
    }
}
