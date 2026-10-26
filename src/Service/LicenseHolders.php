<?php

namespace Base\Forge\Service;

use Base\Forge\Entity\License;
use Base\Forge\Repository\LicenseRepository;

/**
 * Who is behind a licence key a package manager sends: the seat that key
 * was made for, on a valid licence - and then every valid licence with a
 * seat for that seat's e-mail. Composer sends the e-mail with the key (HTTP
 * Basic, `composer config http-basic`); Unity's Package Manager sends the
 * key alone (`token` in .upmconfig.toml: a Bearer) or both (`_auth`).
 */
class LicenseHolders
{
    public function __construct(private readonly LicenseRepository $licenses)
    {
    }

    /**
     * @param string|null $email when given, the seat's own: a key with another e-mail is refused
     *
     * @return array{0: ?string, 1: list<License>} the e-mail, or null when the key (or the pair) is wrong
     */
    public function authenticate(?string $key, ?string $email = null, bool $emailRequired = false): array
    {
        $key = trim((string) $key);
        if ('' === $key || !LicenseKey::looksValid($key) || ($emailRequired && !trim((string) $email))) {
            return [null, []];
        }

        $seat = $this->licenses->findSeatByKey($key);
        if (!$seat || !$seat->getLicense()?->isValid()) {
            return [null, []];
        }
        if (null !== $email && 0 !== strcasecmp((string) $seat->getEmail(), trim($email))) {
            return [null, []];
        }

        return [$seat->getEmail(), $this->licenses->findValidByEmail((string) $seat->getEmail())];
    }

    /**
     * The credentials of an Authorization header: "Bearer <key>", or "Basic
     * base64(<e-mail>:<key>)".
     *
     * @return array{0: ?string, 1: ?string}|null the key and the e-mail (null: none sent), or null when there is no such header
     */
    public static function fromHeader(?string $authorization): ?array
    {
        $authorization = trim((string) $authorization);
        if ('' === $authorization) {
            return null;
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', $authorization, $match)) {
            return [$match[1], null];
        }
        if (preg_match('/^Basic\s+(\S+)$/i', $authorization, $match)) {
            $pair = base64_decode($match[1], true);
            if (false !== $pair && str_contains($pair, ':')) {
                [$email, $key] = explode(':', $pair, 2);

                return [$key, $email];
            }
        }

        return [null, null];
    }
}
