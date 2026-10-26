<?php

namespace Base\Forge\Service;

use Base\Entity\User;
use Base\Forge\Entity\LicenseSeat;
use Base\Forge\Repository\LicenseRepository;
use Psr\Cache\CacheItemPoolInterface;

/**
 * An editor linked to its user's licences, by a device code (as OAuth's device flow): the Unity
 * window asks for a code, the user opens the link page on the site, signs in, buys what they
 * need, and links the editor; the window, which asked meanwhile, then receives a licence key -
 * the key of one of the user's seats, made anew (the seat's previous key stops working) - and
 * stores it as the registry's token in the user's .upmconfig.toml.
 *
 * The codes live ten minutes in the cache, nowhere else; a key is handed over once.
 */
class DeviceLinks
{
    public const TTL = 600;
    public const INTERVAL = 3;
    private const ALPHABET = 'BCDFGHJKLMNPQRSTVWXZ';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LicenseRepository $licenses,
        private readonly LicenseIssuer $issuer,
    ) {
    }

    /** @return array{device_code: string, user_code: string, expires_in: int, interval: int} */
    public function start(?string $item = null): array
    {
        $device = bin2hex(random_bytes(20));
        $user = '';
        for ($i = 0; $i < 8; ++$i) {
            $user .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)].(3 === $i ? '-' : '');
        }
        $expires = time() + self::TTL;
        $this->save('device.'.$device, ['user_code' => $user, 'item' => $item, 'status' => 'pending', 'key' => null, 'polled' => 0, 'expires' => $expires]);
        $this->save('user.'.str_replace('-', '', $user), ['device' => $device, 'expires' => $expires]);

        return ['device_code' => $device, 'user_code' => $user, 'expires_in' => self::TTL, 'interval' => self::INTERVAL];
    }

    /** @return array{device: string, item: ?string, status: string}|null the code a user typed, while it waits */
    public function find(string $userCode): ?array
    {
        $user = $this->load('user.'.strtoupper(preg_replace('/[^A-Za-z]/', '', $userCode)));
        $device = $user ? $this->load('device.'.$user['device']) : null;

        return $device ? ['device' => $user['device'], 'item' => $device['item'], 'status' => $device['status']] : null;
    }

    /**
     * The user links the editor: a seat of theirs on a valid licence gets a new key, kept for the
     * editor to take. Null when they hold no seat to give it.
     */
    public function approve(string $userCode, User $user): ?LicenseSeat
    {
        $found = $this->find($userCode);
        if (!$found || 'pending' !== $found['status']) {
            return null;
        }
        $seat = $this->seatOf($user);
        if (!$seat) {
            return null;
        }
        $entry = $this->load('device.'.$found['device']);
        $entry['status'] = 'approved';
        $entry['key'] = $this->issuer->rekey($seat);
        $entry['email'] = $seat->getEmail();
        $this->save('device.'.$found['device'], $entry);

        return $seat;
    }

    /**
     * What the editor gets when it asks: the key once approved (then the code is spent), else why
     * not - authorization_pending, slow_down, expired_token (RFC 8628's words).
     *
     * @return array{key: string, email: string}|array{error: string}
     */
    public function poll(string $deviceCode): array
    {
        $entry = $this->load('device.'.$deviceCode);
        if (!$entry) {
            return ['error' => 'expired_token'];
        }
        if ('approved' === $entry['status']) {
            $this->cache->deleteItem($this->id('device.'.$deviceCode));
            $this->cache->deleteItem($this->id('user.'.str_replace('-', '', $entry['user_code'])));

            return ['key' => (string) $entry['key'], 'email' => (string) $entry['email']];
        }
        $now = microtime(true);
        $tooSoon = $now - (float) $entry['polled'] < self::INTERVAL - 0.5;
        $entry['polled'] = $now;
        $this->save('device.'.$deviceCode, $entry);

        return ['error' => $tooSoon ? 'slow_down' : 'authorization_pending'];
    }

    /** A seat the user holds, on a valid licence: theirs first (given to their account), the one with a key first. */
    public function seatOf(User $user): ?LicenseSeat
    {
        $best = null;
        foreach ($this->licenses->findHeldBy($user) as $license) {
            if (!$license->isValid()) {
                continue;
            }
            foreach ($license->getHolders() as $seat) {
                if ($seat->isHeldBy($user)) {
                    $best ??= $seat;
                    if ($seat->hasKey()) {
                        return $seat;
                    }
                }
            }
        }

        return $best;
    }

    private function save(string $key, array $value): void
    {
        $item = $this->cache->getItem($this->id($key));
        $item->set($value);
        // The code's own end, whatever is written to it meanwhile.
        $item->expiresAt(new \DateTimeImmutable('@'.($value['expires'] ?? time() + self::TTL)));
        $this->cache->save($item);
    }

    private function load(string $key): ?array
    {
        $item = $this->cache->getItem($this->id($key));

        $value = $item->isHit() ? $item->get() : null;

        return \is_array($value) && ($value['expires'] ?? 0) > time() ? $value : null;
    }

    private function id(string $key): string
    {
        return 'forge_hub_'.str_replace('.', '_', $key);
    }
}
