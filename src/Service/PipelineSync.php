<?php

namespace Base\Forge\Service;

use Base\Forge\Entity\Pipeline;
use Base\Forge\Entity\Project;
use Base\Forge\Model\PipelineRun;
use Base\Forge\Pipeline\PipelineSourceInterface;
use Base\Forge\Repository\PipelineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * A project's runs read where its repository lives (a PipelineSourceInterface: GitHub Actions
 * today) and recorded: a run seen before - the same source and id - is brought up to date, its
 * jobs moved on, the ones no longer run taken out; a new one is added. forge:pipelines:sync runs
 * it for every project with a repository (cron), the back office's pipelines page on demand.
 */
final class PipelineSync
{
    /** @param iterable<PipelineSourceInterface> $sources */
    public function __construct(
        #[AutowireIterator(PipelineSourceInterface::TAG)] private readonly iterable $sources,
        private readonly PipelineRepository $pipelines,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** The source that reads this project's runs, or null when none does. */
    public function source(Project $project): ?PipelineSourceInterface
    {
        foreach ($this->sources as $source) {
            if ($source->supports($project)) {
                return $source;
            }
        }

        return null;
    }

    /**
     * Reads and records the project's latest runs; flushed.
     *
     * @return list<Pipeline> the runs recorded, newest first; empty when no source reads the project
     *
     * @throws \RuntimeException when the host does not answer
     */
    public function sync(Project $project, int $limit = 10): array
    {
        $source = $this->source($project);
        if (null === $source) {
            return [];
        }

        $recorded = [];
        foreach ($source->runs($project, $limit) as $run) {
            $recorded[] = $this->record($project, $run);
        }
        $this->entityManager->flush();

        return $recorded;
    }

    /** One run written into its Pipeline (persisted, not flushed). */
    public function record(Project $project, PipelineRun $run): Pipeline
    {
        $pipeline = $this->pipelines->findOneBySource($run->source, $run->externalId)
            ?? (new Pipeline($project))->setSource($run->source, $run->externalId);
        $pipeline->setProject($project)
            ->setName($run->name)
            ->setRef($run->ref)
            ->setCommitSha($run->commitSha)
            ->setUrl($run->url)
            ->setFinishedAt($run->finishedAt)
            ->setStatus($run->status);
        if ($run->createdAt) {
            $pipeline->setCreatedAt($run->createdAt);
        }

        $kept = [];
        foreach ($run->jobs as $each) {
            $pipeline->stage($each->stage)->job($each->name, $each->status)
                ->setNeeds($each->needs)
                ->setUrl($each->url)
                ->setAllowFailure($each->allowFailure)
                ->setStartedAt($each->startedAt)
                ->setFinishedAt($each->finishedAt);
            $kept[$each->stage][] = $each->name;
        }
        $pipeline->keep($kept);

        $this->entityManager->persist($pipeline);

        return $pipeline;
    }
}
