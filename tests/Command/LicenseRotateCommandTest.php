<?php

namespace Tests\Base\Forge\Command;

use Base\Forge\Command\LicenseKeypairCommand;
use Base\Forge\Command\LicenseRotateCommand;
use Base\Forge\Service\LicenseKeyVault;
use Base\Forge\Service\LicenseToken;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Secrets\DotenvVault;
use Symfony\Bundle\FrameworkBundle\Secrets\SodiumVault;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Base\Forge\LicenseEnvironment;

/**
 * The operator's commands: the first key sealed in the vault, then a rotation the current key
 * signs over - each run against a vault of its own (a temporary config/secrets/<env>/ and
 * .env.<env>.local), never printing a secret.
 */
class LicenseRotateCommandTest extends KernelTestCase
{
    use LicenseEnvironment;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/forge-vault-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->licenseEnvironment();
    }

    protected function tearDown(): void
    {
        $this->restoreLicenseEnvironment();
        (new \Symfony\Component\Filesystem\Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    public function testTheCommandsAreThereInTheApplication(): void
    {
        $_SERVER['KERNEL_CLASS'] ??= $_ENV['KERNEL_CLASS'] ?? 'App\\Kernel';
        if (!class_exists($_SERVER['KERNEL_CLASS'])) {
            self::markTestSkipped('Needs a host application kernel.');
        }
        $application = new Application(self::bootKernel());
        self::assertTrue($application->has('forge:license:keypair'));
        // Wired with the host's vault; without a key in the test environment it refuses, writing nothing.
        $rotate = new CommandTester($application->find('forge:license:rotate'));
        self::assertSame(Command::FAILURE, $rotate->execute([]));
        self::assertStringContainsString('forge:license:keypair', $rotate->getDisplay());
    }

    public function testAFirstKeyThenARotationSignedOverByIt(): void
    {
        // The first key, sealed: the public key printed, the secret nowhere.
        $keypair = new CommandTester(new LicenseKeypairCommand(new LicenseToken(), $this->vault()));
        self::assertSame(Command::SUCCESS, $keypair->execute([]));
        $first = $this->reveal(LicenseKeyVault::SIGNING_KEY);
        self::assertNotNull($first);
        $firstPublic = (new LicenseToken($first))->publicKey();
        self::assertStringContainsString($firstPublic, $keypair->getDisplay());
        self::assertStringContainsString(LicenseToken::fingerprint($firstPublic), $keypair->getDisplay());
        $this->assertNoSecretIn($keypair->getDisplay(), $first);

        // Once a key is set, the keypair refuses: rotating is the way.
        $again = new CommandTester(new LicenseKeypairCommand(new LicenseToken($first), $this->vault()));
        self::assertSame(Command::FAILURE, $again->execute([]));
        self::assertStringContainsString('forge:license:rotate', $again->getDisplay());
        self::assertSame($first, $this->reveal(LicenseKeyVault::SIGNING_KEY), 'untouched');

        // The rotation: a new secret, the previous public key and its signature of the new one.
        $before = time();
        $rotate = new CommandTester(new LicenseRotateCommand(new LicenseToken($first, '10'), $this->vault()));
        self::assertSame(Command::SUCCESS, $rotate->execute([]));
        $second = $this->reveal(LicenseKeyVault::SIGNING_KEY);
        self::assertNotSame($first, $second);
        $this->assertNoSecretIn($rotate->getDisplay(), $first);
        $this->assertNoSecretIn($rotate->getDisplay(), $second);
        $secondPublic = (new LicenseToken($second))->publicKey();
        self::assertSame($firstPublic, $this->reveal(LicenseKeyVault::PREVIOUS_KEY));
        self::assertTrue(LicenseToken::rotationVerifies($firstPublic, $this->reveal(LicenseKeyVault::ROTATION), $secondPublic));
        $until = (int) $this->reveal(LicenseKeyVault::PREVIOUS_UNTIL);
        self::assertGreaterThanOrEqual($before + 10 * 86400, $until, 'the previous key\'s tokens run their grace days out');
        self::assertLessThanOrEqual(time() + 10 * 86400, $until);

        // What the forge reads from that vault publishes the rotation.
        $read = new LicenseToken($second, '10', $this->reveal(LicenseKeyVault::PREVIOUS_KEY), $this->reveal(LicenseKeyVault::ROTATION), $this->reveal(LicenseKeyVault::PREVIOUS_UNTIL));
        self::assertSame(['previousKey' => $firstPublic, 'rotation' => $this->reveal(LicenseKeyVault::ROTATION), 'previousKeyValidUntil' => $until], $read->rotation());

        // A leaked key: its tokens end now.
        $leak = new CommandTester(new LicenseRotateCommand($read, $this->vault()));
        self::assertSame(Command::SUCCESS, $leak->execute(['--compromised' => true]));
        self::assertSame($secondPublic, $this->reveal(LicenseKeyVault::PREVIOUS_KEY), 'one rotation back: the last one');
        self::assertLessThanOrEqual(time(), (int) $this->reveal(LicenseKeyVault::PREVIOUS_UNTIL));
        $this->assertNoSecretIn($leak->getDisplay(), $this->reveal(LicenseKeyVault::SIGNING_KEY));
    }

    public function testTheLocalVaultAndAValueThatWinsOverTheVault(): void
    {
        $pair = LicenseToken::keypair();

        // Set as an environment variable: the vault would change nothing, so nothing is written.
        $this->licenseEnvironment([LicenseKeyVault::SIGNING_KEY => $pair['secret']]);
        $rotate = new CommandTester(new LicenseRotateCommand(new LicenseToken($pair['secret']), $this->vault()));
        self::assertSame(Command::FAILURE, $rotate->execute([]));
        self::assertStringContainsString('environment variable', $rotate->getDisplay());
        $this->assertNoSecretIn($rotate->getDisplay(), $pair['secret']);
        self::assertNull($this->reveal(LicenseKeyVault::SIGNING_KEY));
        self::assertSame(Command::FAILURE, (new CommandTester(new LicenseRotateCommand(new LicenseToken($pair['secret']), $this->vault())))->execute(['--local' => true]), 'a real variable wins over .env.<env>.local too');

        // Read from a .env file: the local vault, read after it, takes it over.
        $dotenvVars = $_SERVER['SYMFONY_DOTENV_VARS'] ?? null;
        $_SERVER['SYMFONY_DOTENV_VARS'] = ltrim($dotenvVars.','.LicenseKeyVault::SIGNING_KEY, ',');
        try {
            self::assertSame(Command::FAILURE, (new CommandTester(new LicenseRotateCommand(new LicenseToken($pair['secret']), $this->vault())))->execute([]));
            $local = new CommandTester(new LicenseRotateCommand(new LicenseToken($pair['secret']), $this->vault()));
            self::assertSame(Command::SUCCESS, $local->execute(['--local' => true]));
        } finally {
            if (null === $dotenvVars) {
                unset($_SERVER['SYMFONY_DOTENV_VARS']);
            } else {
                $_SERVER['SYMFONY_DOTENV_VARS'] = $dotenvVars;
            }
        }
        $dotenv = (string) file_get_contents($this->directory.'/.env.test.local');
        self::assertMatchesRegularExpression('/^FORGE_LICENSE_SIGNING_KEY=\'[A-Za-z0-9+\/=]{88}\'$/m', $dotenv);
        self::assertStringContainsString("FORGE_LICENSE_PREVIOUS_KEY='".(new LicenseToken($pair['secret']))->publicKey()."'", $dotenv);
        self::assertStringNotContainsString($pair['secret'], $dotenv, 'the previous secret is not kept');
        self::assertLessThan(strpos($dotenv, 'FORGE_LICENSE_SIGNING_KEY'), strpos($dotenv, 'FORGE_LICENSE_ROTATION'), 'the new secret written last');
        $this->assertNoSecretIn($local->getDisplay(), $pair['secret']);

        // No key at all: nothing to rotate.
        $this->licenseEnvironment();
        $none = new CommandTester(new LicenseRotateCommand(new LicenseToken(), $this->vault()));
        self::assertSame(Command::FAILURE, $none->execute([]));
        self::assertStringContainsString('forge:license:keypair', $none->getDisplay());
    }

    private function vault(): LicenseKeyVault
    {
        return new LicenseKeyVault(new SodiumVault($this->directory.'/secrets/test'), new DotenvVault($this->directory.'/.env.test.local'));
    }

    private function reveal(string $name): ?string
    {
        return (new SodiumVault($this->directory.'/secrets/test'))->reveal($name);
    }

    private function assertNoSecretIn(string $output, ?string $secret): void
    {
        self::assertNotNull($secret);
        $flat = preg_replace('/\s+/', '', $output);
        self::assertStringNotContainsString($secret, $flat, 'the secret key is never printed');
        // Nor its seed half alone (the first 32 bytes of sodium's secret key).
        self::assertStringNotContainsString(rtrim(base64_encode(substr(base64_decode($secret), 0, 32)), '='), $flat);
    }
}
