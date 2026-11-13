<?php

namespace Base\Forge\Command;

use Base\Forge\Service\LicenseKeyVault;
use Base\Forge\Service\LicenseToken;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A new signing key for the licence tokens, handed over by the current one (plan item 17.1): the
 * current key signs "forge-key-rotation:v1:" + the new public key, and the hub manifest publishes
 * that signature with the previous key (licensing.previousKey, licensing.rotation), so that a
 * Forge Store which pinned the previous key moves to the new one without asking its user.
 *
 * Sealed in the secrets vault, the new secret last: the new secret key, the previous public key,
 * the signature, and until when the previous key's tokens may run - the grace days from now (no
 * token it signed runs longer), or now with --compromised. Nothing secret is printed.
 */
#[AsCommand('forge:license:rotate', 'Replace the licence signing key with a new one, signed over by the current key')]
class LicenseRotateCommand extends Command
{
    public function __construct(
        private readonly LicenseToken $tokens,
        private readonly LicenseKeyVault $vault,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('local', 'l', InputOption::VALUE_NONE, 'Seal it in the local vault (.env.<env>.local) rather than config/secrets/<env>/')
            ->addOption('compromised', null, InputOption::VALUE_NONE, 'The current key leaked: the tokens it signed end now, rather than after the grace days');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $local = (bool) $input->getOption('local');

        if (!$this->tokens->isConfigured()) {
            $io->error('No signing key is set yet: bin/console forge:license:keypair makes the first one.');

            return Command::FAILURE;
        }
        if (!$this->vault->has($local)) {
            $io->error($local ? 'The local vault is disabled.' : 'The secrets vault is disabled (framework.secrets).');

            return Command::FAILURE;
        }
        if ($reason = $this->vault->overriddenBy($local)) {
            $io->error($reason.'.');

            return Command::FAILURE;
        }

        $next = $this->tokens->successor();
        $until = time() + ($input->getOption('compromised') ? 0 : $this->tokens->graceDays() * 86400);
        $messages = $this->vault->store($local, [
            LicenseKeyVault::PREVIOUS_KEY => $next['previous'],
            LicenseKeyVault::ROTATION => $next['rotation'],
            LicenseKeyVault::PREVIOUS_UNTIL => (string) $until,
            LicenseKeyVault::SIGNING_KEY => $next['secret'],
        ]);
        unset($next['secret']);

        $io->listing($messages);
        $io->definitionList(
            ['Previous key' => $next['previous'].' ('.LicenseToken::fingerprint($next['previous']).')'],
            ['New key' => $next['public'].' ('.LicenseToken::fingerprint($next['public']).')'],
            ['Previous key\'s tokens' => 'run until '.gmdate('Y-m-d H:i', $until).' UTC at the latest'],
        );
        if ($input->getOption('compromised')) {
            $io->warning('The previous key leaked: whoever holds it can sign a rotation of their own as well. Tell the stores\' users which key is the new one (its fingerprint above).');
        }
        $io->success('The new secret key is sealed in the vault and was shown nowhere. Deploy the vault and clear the cache: the hub manifest then publishes the new key and the signed rotation; the next activations are signed with it. A game that ships the previous key needs a release with the new one.');

        return Command::SUCCESS;
    }
}
