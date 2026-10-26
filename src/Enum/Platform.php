<?php

namespace Base\Forge\Enum;

/**
 * A platform a game or an application is built for. Its build is a release
 * artifact whose format is the platform's value: a zip of the build folder
 * (webgl, windows, macos, linux) or the package itself (android: an .apk or
 * an .aab).
 */
enum Platform: string
{
    case WEBGL = 'webgl';
    case WINDOWS = 'windows';
    case MACOS = 'macos';
    case LINUX = 'linux';
    case ANDROID = 'android';

    public function label(): string
    {
        return match ($this) {
            self::WEBGL => 'Web',
            self::WINDOWS => 'Windows',
            self::MACOS => 'macOS',
            self::LINUX => 'Linux',
            self::ANDROID => 'Android',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::WEBGL => 'fa-solid fa-globe',
            self::WINDOWS => 'fa-brands fa-windows',
            self::MACOS => 'fa-brands fa-apple',
            self::LINUX => 'fa-brands fa-linux',
            self::ANDROID => 'fa-brands fa-android',
        };
    }

    /** @return list<string> the file extensions its build may come as */
    public function extensions(): array
    {
        return match ($this) {
            self::ANDROID => ['apk', 'aab'],
            self::MACOS => ['zip', 'dmg'],
            default => ['zip'],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $platform) => $platform->value, self::cases());
    }
}
