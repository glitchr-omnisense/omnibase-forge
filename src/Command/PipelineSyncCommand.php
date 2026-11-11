<?php

namespace Base\Forge\Command;

use Base\Forge\Repository\ProjectRepository;
use Base\Forge\Service\PipelineSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The projects' runs, read from the CI of the host each repository lives on (Service\PipelineSync).
 * Run by cron every few minutes; a project whose host has no source is passed over.
 */
#[AsCommand(name: 'forge:pipelines:sync', description: 'Read the latest CI runs of the projects from the host of their repository')]
class PipelineSyncCommand extends Command
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly PipelineSync $sync,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('projects', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Only these projects (id or slug)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many of the latest runs of each project', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getArgument('projects');
        $limit = max(1, (int) $input->getOption('limit'));
        $failed = false;

        foreach ($this->projects->findWithRepositoryUrl() as $project) {
            if ($only && !\in_array((string) $project->getId(), $only, true) && !\in_array((string) $project->getSlug(), $only, true)) {
                continue;
            }
            if (null === $this->sync->source($project)) {
                $io->writeln(sprintf('<comment>%s</comment>: no source reads %s', $project->getName(), $project->getRepositoryUrl()), OutputInterface::VERBOSITY_VERBOSE);
                continue;
            }
            try {
                $runs = $this->sync->sync($project, $limit);
                $io->writeln(sprintf('<info>%s</info>: %d run(s)', $project->getName(), \count($runs)));
            } catch (\RuntimeException $e) {
                $failed = true;
                $io->error(sprintf('%s: %s', $project->getName(), $e->getMessage()));
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
