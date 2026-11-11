<?php

namespace Tests\Base\Forge\Service;

use Base\Forge\Entity\Pipeline;
use Base\Forge\Entity\Project;
use Base\Forge\Enum\PipelineStatus;
use Base\Forge\Model\PipelineRun;
use Base\Forge\Model\PipelineRunJob;
use Base\Forge\Pipeline\PipelineSourceInterface;
use Base\Forge\Repository\PipelineRepository;
use Base\Forge\Service\PipelineSync;
use Tests\Base\Forge\ForgeWebTestCase;

/** A project's runs recorded from its source, in a host application: added once, then brought up to date. */
class PipelineSyncTest extends ForgeWebTestCase
{
    public function testARunIsRecordedThenBroughtUpToDate(): void
    {
        $client = $this->user('client');
        $project = (new Project($client, 'Site '.bin2hex(random_bytes(3))))->setRepositoryUrl('https://ci.example.org/acme/site');
        $project->setClient($client);
        $this->em->persist($project);
        $this->em->flush();

        $id = bin2hex(random_bytes(6));
        $source = new class($id) implements PipelineSourceInterface {
            public array $runs = [];

            public function __construct(private readonly string $id)
            {
            }

            public function supports(Project $project): bool
            {
                return str_starts_with((string) $project->getRepositoryUrl(), 'https://ci.example.org/');
            }

            public function runs(Project $project, int $limit = 10): array
            {
                return $this->runs;
            }
        };
        $sync = new PipelineSync([$source], static::getContainer()->get(PipelineRepository::class), $this->em);
        self::assertSame($source, $sync->source($project));
        self::assertNull($sync->source((new Project())->setRepositoryUrl('https://elsewhere.example.org/x')), 'no source reads it');

        $source->runs = [new PipelineRun('test', $id, 'CI', 'main', str_repeat('d', 40), 'https://ci.example.org/runs/1', new \DateTimeImmutable('2026-11-11 08:00:00'), null, null, [
            new PipelineRunJob('lint', 'check', PipelineStatus::SUCCESS),
            new PipelineRunJob('unit', 'test', PipelineStatus::RUNNING, ['lint'], 'https://ci.example.org/jobs/2'),
            new PipelineRunJob('e2e', 'test', PipelineStatus::PENDING, ['lint']),
        ])];
        [$recorded] = $sync->sync($project);
        $this->em->clear();

        $pipeline = $this->em->getRepository(Pipeline::class)->findOneBy(['source' => 'test', 'externalId' => $id]);
        self::assertSame($recorded->getId(), $pipeline->getId());
        self::assertSame(['CI', 'main', PipelineStatus::RUNNING], [$pipeline->getName(), $pipeline->getRef(), $pipeline->getStatus()]);
        self::assertSame(['check' => ['lint'], 'test' => ['unit', 'e2e']], $this->jobs($pipeline));
        self::assertSame(['lint'], $pipeline->getStages()[1]->getJobs()[0]->getNeeds());

        // The same run, later: the jobs moved on, one is no longer run.
        $source->runs = [new PipelineRun('test', $id, 'CI', 'main', str_repeat('d', 40), 'https://ci.example.org/runs/1', new \DateTimeImmutable('2026-11-11 08:00:00'), new \DateTimeImmutable('2026-11-11 08:04:00'), null, [
            new PipelineRunJob('lint', 'check', PipelineStatus::SUCCESS),
            new PipelineRunJob('unit', 'test', PipelineStatus::FAILED, ['lint']),
        ])];
        $sync->sync($this->em->find(Project::class, $project->getId()));
        $this->em->clear();

        $all = $this->em->getRepository(Pipeline::class)->findBy(['source' => 'test', 'externalId' => $id]);
        self::assertCount(1, $all, 'brought up to date, not recorded twice');
        self::assertSame(PipelineStatus::FAILED, $all[0]->getStatus());
        self::assertSame(240, $all[0]->getDuration());
        self::assertSame(['check' => ['lint'], 'test' => ['unit']], $this->jobs($all[0]));
    }

    /** @return array<string, list<string>> */
    private function jobs(Pipeline $pipeline): array
    {
        $jobs = [];
        foreach ($pipeline->getStages() as $stage) {
            foreach ($stage->getJobs() as $job) {
                $jobs[$stage->getName()][] = $job->getName();
            }
        }

        return $jobs;
    }
}
