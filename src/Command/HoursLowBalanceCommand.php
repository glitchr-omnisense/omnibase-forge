<?php

namespace Base\Forge\Command;

use Base\Entity\User;
use Base\Forge\Repository\HourCreditRepository;
use Base\Database\Repository\Repositories;
use Base\Contract\UnitOfWork;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Tells the clients whose support hours run low (under
 * forge.low_balance_minutes, or overdrawn) - weekly, by cron.
 */
#[AsCommand(name: 'forge:hours:low-balance', description: 'E-mail the clients whose support hours are running out')]
class HoursLowBalanceCommand extends Command
{
    public function __construct(
        private readonly HourCreditRepository $credits,
        private readonly UnitOfWork $unitOfWork,
        private readonly Repositories $repositories,
        private readonly MailerInterface $mailer,
        #[Autowire('%forge.low_balance_minutes%')] private readonly int $threshold = 120,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List them, send nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sent = 0;

        foreach ($this->credits->lowBalances($this->threshold) as $id => $balance) {
            $user = $this->repositories->of(User::class)->find($id);
            if (!$user instanceof User || !$user->getEmail()) {
                continue;
            }

            $io->writeln(sprintf(' %s: %dh%02d', $user->getEmail(), intdiv((int) $balance, 60), abs((int) $balance) % 60));
            if ($input->getOption('dry-run')) {
                continue;
            }

            $this->mailer->send((new TemplatedEmail())
                ->to($user->getEmail())
                ->subject('Vos heures de support')
                ->htmlTemplate('@Forge/email/low_balance.html.twig')
                ->context(['user' => $user, 'balance' => (int) $balance]));
            ++$sent;
        }

        $io->success(sprintf('%d reminder(s) sent.', $sent));

        return Command::SUCCESS;
    }
}
