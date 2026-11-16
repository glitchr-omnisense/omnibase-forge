<?php

namespace Base\Forge\Command;

use Base\Forge\Enum\LicenseStatus;
use Base\Forge\Repository\LicenseRepository;
use Base\Contract\UnitOfWork;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Marks the licences past their expiry as expired. They already stopped
 * working at that instant (License::isValid() reads the date); this keeps
 * the status honest for the back office and the account page.
 */
#[AsCommand(name: 'forge:license:expire', description: 'Mark the licences past their expiry date as expired')]
class LicenseExpireCommand extends Command
{
    public function __construct(
        private readonly LicenseRepository $licenses,
        private readonly UnitOfWork $unitOfWork,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lapsed = $this->licenses->findLapsed(new \DateTimeImmutable());
        foreach ($lapsed as $license) {
            $license->setStatus(LicenseStatus::EXPIRED);
        }
        $this->unitOfWork->flush();

        (new SymfonyStyle($input, $output))->success(sprintf('%d licence(s) expired.', \count($lapsed)));

        return Command::SUCCESS;
    }
}
