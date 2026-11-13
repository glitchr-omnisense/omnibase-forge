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
 * The forge's first Ed25519 pair for the licence tokens: the secret sealed in the secrets vault
 * (FORGE_LICENSE_SIGNING_KEY) without ever being shown, the public key printed - the hub manifest
 * publishes it (licensing.publicKey), a game ships it. Once a key is set, forge:license:rotate
 * replaces it.
 */
#[AsCommand('forge:license:keypair', 'Make the key pair that signs the licence tokens; the secret is sealed in the secrets vault')]
class LicenseKeypairCommand extends Command
{
    public function __construct(
        private readonly LicenseToken $tokens,
        private readonly LicenseKeyVault $vault,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('local', 'l', InputOption::VALUE_NONE, 'Seal it in the local vault (.env.<env>.local) rather than config/secrets/<env>/');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $local = (bool) $input->getOption('local');

        if ($this->tokens->isConfigured()) {
            $io->error('A signing key is already set: bin/console forge:license:rotate replaces it, signed by the current one.');

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

        $pair = LicenseToken::keypair();
        $messages = $this->vault->store($local, [
            LicenseKeyVault::PREVIOUS_KEY => null,
            LicenseKeyVault::ROTATION => null,
            LicenseKeyVault::PREVIOUS_UNTIL => null,
            LicenseKeyVault::SIGNING_KEY => $pair['secret'],
        ]);
        unset($pair['secret']);

        $io->listing($messages);
        $io->definitionList(
            ['Public key' => $pair['public']],
            ['Fingerprint' => LicenseToken::fingerprint($pair['public'])],
        );
        $io->success('The secret key is sealed in the vault and was shown nowhere. Deploy the vault and clear the cache: the hub manifest then publishes the public key (licensing.publicKey).');

        return Command::SUCCESS;
    }
}
