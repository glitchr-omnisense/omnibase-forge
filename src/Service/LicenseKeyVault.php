<?php

namespace Base\Forge\Service;

use Symfony\Bundle\FrameworkBundle\Secrets\AbstractVault;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where the licence signing key lives: Symfony's secrets vault (config/secrets/<env>/, sealed with
 * the environment's encryption key), or its local vault (.env.<env>.local) - the vault
 * bin/console secrets:set writes. The forge's commands seal the key there themselves, so that the
 * secret is never printed, typed or copied by hand.
 *
 * A value set higher up wins over the vault (Symfony reads $_ENV, $_SERVER and getenv() first,
 * the vault only when they hold nothing): overriddenBy() tells when writing to the vault would
 * change nothing.
 */
class LicenseKeyVault
{
    public const SIGNING_KEY = 'FORGE_LICENSE_SIGNING_KEY';
    public const PREVIOUS_KEY = 'FORGE_LICENSE_PREVIOUS_KEY';
    public const ROTATION = 'FORGE_LICENSE_ROTATION';
    public const PREVIOUS_UNTIL = 'FORGE_LICENSE_PREVIOUS_UNTIL';

    public function __construct(
        #[Autowire('@?secrets.vault')] private readonly ?AbstractVault $vault = null,
        #[Autowire('@?secrets.local_vault')] private readonly ?AbstractVault $localVault = null,
    ) {
    }

    public function has(bool $local): bool
    {
        return null !== ($local ? $this->localVault : $this->vault);
    }

    /**
     * Why the signing key the vault would hold is not the one the forge would read: null when the
     * vault's value is the one read. The value itself is never part of the answer.
     */
    public function overriddenBy(bool $local): ?string
    {
        $value = $_ENV[self::SIGNING_KEY] ?? $_SERVER[self::SIGNING_KEY] ?? getenv(self::SIGNING_KEY);
        if (!\is_string($value) || '' === trim($value)) {
            return null;
        }
        $fromDotenv = \in_array(self::SIGNING_KEY, explode(',', (string) ($_SERVER['SYMFONY_DOTENV_VARS'] ?? $_ENV['SYMFONY_DOTENV_VARS'] ?? '')), true);
        if ($local && $fromDotenv) {
            // .env.<env>.local is read after .env and .env.<env>: what the local vault writes wins.
            return null;
        }

        return $fromDotenv
            ? self::SIGNING_KEY.' is set in a .env file, which wins over the vault: remove it there, or use --local'
            : self::SIGNING_KEY.' is set as an environment variable, which wins over the vault: change it where it is set';
    }

    /**
     * Seals the values (name => value) in the vault, the signing key last: until it is written,
     * what the others describe does not match the key in use and is not published
     * (LicenseToken::rotation()). Empty values remove their secret.
     *
     * @param array<string, string|null> $values
     *
     * @return list<string> what the vault said, to show the operator (never a value)
     */
    public function store(bool $local, #[\SensitiveParameter] array $values): array
    {
        $vault = ($local ? $this->localVault : $this->vault) ?? throw new \LogicException($local ? 'The local vault is disabled.' : 'The secrets vault is disabled (framework.secrets).');
        $messages = [];
        if ($vault->generateKeys()) {
            $messages[] = (string) $vault->getLastMessage();
        }
        if (\array_key_exists(self::SIGNING_KEY, $values)) {
            $signing = $values[self::SIGNING_KEY];
            unset($values[self::SIGNING_KEY]);
            $values[self::SIGNING_KEY] = $signing;
        }
        foreach ($values as $name => $value) {
            if (null === $value || '' === $value) {
                if ($vault->remove($name)) {
                    $messages[] = sprintf('%s removed.', $name);
                }
                continue;
            }
            $vault->seal($name, $value);
            $messages[] = (string) ($vault->getLastMessage() ?? sprintf('%s sealed.', $name));
        }

        return $messages;
    }
}
