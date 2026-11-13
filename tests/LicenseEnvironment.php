<?php

namespace Tests\Base\Forge;

/**
 * The licence key variables as a test wants them, whatever the process environment holds: a host
 * whose container exports FORGE_LICENSE_SIGNING_KEY (a site loading its .env.local into the
 * environment) would otherwise win, Symfony reading getenv() when $_ENV and $_SERVER hold
 * nothing. Each variable is set or removed in $_ENV, $_SERVER and putenv() alike, and taken out
 * of SYMFONY_DOTENV_VARS; restoreLicenseEnvironment() puts everything back as it was.
 */
trait LicenseEnvironment
{
    private const LICENSE_VARIABLES = [
        'FORGE_LICENSE_SIGNING_KEY',
        'FORGE_LICENSE_PREVIOUS_KEY',
        'FORGE_LICENSE_ROTATION',
        'FORGE_LICENSE_PREVIOUS_UNTIL',
        'FORGE_LICENSE_GRACE_DAYS',
    ];

    /** @var array<string, array{0: array{bool, mixed}, 1: array{bool, mixed}, 2: string|false}>|null */
    private ?array $savedLicenseEnvironment = null;

    /** @param array<string, string> $values the variables to set; the others are removed */
    protected function licenseEnvironment(array $values = []): void
    {
        if (null === $this->savedLicenseEnvironment) {
            $this->savedLicenseEnvironment = [];
            foreach ([...self::LICENSE_VARIABLES, 'SYMFONY_DOTENV_VARS'] as $name) {
                $this->savedLicenseEnvironment[$name] = [
                    [\array_key_exists($name, $_ENV), $_ENV[$name] ?? null],
                    [\array_key_exists($name, $_SERVER), $_SERVER[$name] ?? null],
                    getenv($name),
                ];
            }
        }

        foreach (self::LICENSE_VARIABLES as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
            if (isset($values[$name])) {
                $_ENV[$name] = $_SERVER[$name] = $values[$name];
                putenv($name.'='.$values[$name]);
            }
        }
        $strip = static fn (mixed $list): mixed => \is_string($list) ? implode(',', array_diff(explode(',', $list), self::LICENSE_VARIABLES)) : $list;
        if (isset($_ENV['SYMFONY_DOTENV_VARS'])) {
            $_ENV['SYMFONY_DOTENV_VARS'] = $strip($_ENV['SYMFONY_DOTENV_VARS']);
        }
        if (isset($_SERVER['SYMFONY_DOTENV_VARS'])) {
            $_SERVER['SYMFONY_DOTENV_VARS'] = $strip($_SERVER['SYMFONY_DOTENV_VARS']);
        }
    }

    protected function restoreLicenseEnvironment(): void
    {
        foreach ($this->savedLicenseEnvironment ?? [] as $name => [[$inEnv, $env], [$inServer, $server], $process]) {
            unset($_ENV[$name], $_SERVER[$name]);
            if ($inEnv) {
                $_ENV[$name] = $env;
            }
            if ($inServer) {
                $_SERVER[$name] = $server;
            }
            putenv(false === $process ? $name : $name.'='.$process);
        }
        $this->savedLicenseEnvironment = null;
    }
}
