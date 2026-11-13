<?php

namespace Tests\Base\Forge\DependencyInjection;

use Base\Forge\DependencyInjection\ForgeConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/** forge.hub.color is checked at boot: #rrggbb, or none - never a hub.json that fails later. */
final class ForgeConfigurationTest extends TestCase
{
    private function hub(array $hub): array
    {
        return (new Processor())->processConfiguration(new ForgeConfiguration(), [['hub' => $hub]])['hub'];
    }

    public function testAColourWrittenRrggbbOrNoneIsAccepted(): void
    {
        self::assertSame('#fc6875', $this->hub(['color' => '#fc6875'])['color']);
        self::assertSame('#A0B1C2', $this->hub(['color' => '#A0B1C2'])['color']);
        self::assertNull($this->hub([])['color']);
        self::assertNull($this->hub(['color' => null])['color']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function notAColour(): iterable
    {
        yield 'a name' => ['red'];
        yield 'three digits' => ['#fff'];
        yield 'no hash' => ['fc6875'];
        yield 'a trailing space' => ['#fc6875 '];
        yield 'with alpha' => ['#fc6875ff'];
        yield 'not hexadecimal' => ['#fc687g'];
        yield 'empty' => [''];
    }

    /** @dataProvider notAColour */
    public function testAnythingElseIsAConfigurationError(mixed $color): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('forge.hub.color must be a colour written #rrggbb');
        $this->hub(['color' => $color]);
    }
}
