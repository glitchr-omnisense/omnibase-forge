<?php

namespace Base\Forge\Command;

use Base\Forge\Entity\Release;
use Base\Forge\Entity\Software;
use Base\Forge\Enum\Platform;
use Base\Forge\Repository\SoftwareRepository;
use Base\Forge\Service\ArtifactBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A build made elsewhere (Unity, a CI) becomes a release's artifact for a
 * platform - the release made when it does not exist yet (a game need not
 * come from a repository's tags):
 *
 *     bin/console forge:artifact:attach my-game 1.0.0 webgl build/webgl.zip --publish --changelog "First playable"
 */
#[AsCommand(name: 'forge:artifact:attach', description: 'Attach a platform build (webgl, windows, macos, linux, android) to a release')]
class ArtifactAttachCommand extends Command
{
    public function __construct(
        private readonly SoftwareRepository $software,
        private readonly ArtifactBuilder $builder,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'The software')
            ->addArgument('version', InputArgument::REQUIRED, 'The release (made if it does not exist)')
            ->addArgument('platform', InputArgument::REQUIRED, implode(', ', Platform::values()))
            ->addArgument('file', InputArgument::REQUIRED, 'The build: a zip (webgl, windows, macos, linux), a .dmg, an .apk or .aab')
            ->addOption('publish', null, InputOption::VALUE_NONE, 'Publish the release now if it is not published')
            ->addOption('changelog', null, InputOption::VALUE_REQUIRED, 'The release\'s changelog, when it has none');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $software = $this->software->findOneBy(['slug' => $input->getArgument('slug')]);
        if (!$software instanceof Software) {
            $io->error(sprintf('No software "%s".', $input->getArgument('slug')));

            return Command::FAILURE;
        }
        $platform = Platform::tryFrom((string) $input->getArgument('platform'));
        if (!$platform) {
            $io->error(sprintf('"%s" is no platform: %s.', $input->getArgument('platform'), implode(', ', Platform::values())));

            return Command::FAILURE;
        }

        $version = (string) $input->getArgument('version');
        $release = $software->findRelease($version) ?? new Release($software, $version);
        if ($input->getOption('changelog') && !$release->getChangelog()) {
            $release->setChangelog((string) $input->getOption('changelog'));
        }
        if ($input->getOption('publish') && !$release->isPublished()) {
            $release->setPublishedAt(new \DateTimeImmutable());
        }

        try {
            $artifact = $this->builder->attach($release, $platform, (string) $input->getArgument('file'));
        } catch (\InvalidArgumentException $e) {
            if (!$release->getId()) {
                $software->removeRelease($release);
            }
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $this->entityManager->persist($release);
        $this->entityManager->flush();

        $io->success(sprintf('%s %s: %s build attached (%s, %d bytes, sha256 %s).', $software->getName(), $version, $platform->label(), $artifact->getFilename(), $artifact->getSize(), $artifact->getSha256()));

        return Command::SUCCESS;
    }
}
