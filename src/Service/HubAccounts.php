<?php

namespace Base\Forge\Service;

use Base\Entity\User;
use Base\Forge\Entity\HubToken;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The accounts the hub clients act for (plan item 16.1): the device flow (DeviceLinks) links an
 * editor or a desktop hub to its user and hands it a token of the account, "gla_" and 32
 * characters of Crockford base32 as a licence key; the client sends it as a Bearer token to buy,
 * to manage its payment methods and to list its licences. Only the token's hash is stored. Each
 * link is a token of its own: the account lists them and revokes any (docs/hub.md, "Linked
 * editors and signing out").
 */
class HubAccounts
{
    private const BEARER = '/^Bearer\s+(gla_[0-9A-HJKMNP-TV-Z]{4}(?:-[0-9A-HJKMNP-TV-Z]{4}){7})\s*$/';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** A new token for the user, returned in clear this once. Flushed. */
    public function issue(User $user): string
    {
        $token = HubToken::PREFIX.substr(LicenseKey::generate(), \strlen(LicenseKey::PREFIX));
        $this->entityManager->persist(new HubToken($user, $token));
        $this->entityManager->flush();

        return $token;
    }

    /** The user of an "Authorization: Bearer gla_..." header, or null: no token, or not one of ours. */
    public function authenticate(?string $authorization): ?User
    {
        return $this->token($authorization)?->getUser();
    }

    /** Whether the header carries an account token ("Bearer gla_..."), valid or not: not a licence key. */
    public static function isAccountBearer(?string $authorization): bool
    {
        return null !== $authorization && 1 === preg_match('/^Bearer\s+gla_/i', trim($authorization));
    }

    /** The stored token of an "Authorization: Bearer gla_..." header, or null; its use noted once a day. */
    public function token(?string $authorization): ?HubToken
    {
        if (null === $authorization || !preg_match(self::BEARER, $authorization, $match)) {
            return null;
        }
        $token = $this->entityManager->getRepository(HubToken::class)->findOneBy(['tokenHash' => HubToken::hash($match[1])]);
        if (!$token instanceof HubToken) {
            return null;
        }
        // Once a day is enough to tell a token in use from a forgotten one.
        $now = new \DateTimeImmutable();
        if (null === $token->getLastUsedAt() || $token->getLastUsedAt() < $now->modify('-1 day')) {
            $token->markUsed($now);
            $this->entityManager->flush();
        }

        return $token;
    }

    /**
     * The user's tokens, newest first: one per editor or desktop hub linked.
     *
     * @return list<HubToken>
     */
    public function tokensOf(User $user): array
    {
        return array_values($this->entityManager->getRepository(HubToken::class)->findBy(['user' => $user], ['createdAt' => 'DESC', 'id' => 'DESC']));
    }

    /**
     * Revokes one of the user's tokens: what holds it is signed out (401 from then on). False when
     * that id is not one of the user's - nothing revoked. Flushed.
     */
    public function revoke(User $user, int $id): bool
    {
        $token = $this->entityManager->find(HubToken::class, $id);
        if (!$token instanceof HubToken || $token->getUser()->getId() !== $user->getId()) {
            return false;
        }
        $this->entityManager->remove($token);
        $this->entityManager->flush();

        return true;
    }

    /**
     * A token as the clients read it (no secret: its prefix only).
     *
     * @return array{id: int, prefix: string, created: string, last_used: ?string, current: bool}
     */
    public static function describe(HubToken $token, ?HubToken $current = null): array
    {
        return [
            'id' => (int) $token->getId(),
            'prefix' => $token->getTokenPrefix(),
            'created' => $token->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            'last_used' => $token->getLastUsedAt()?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            'current' => null !== $current && $current->getId() === $token->getId(),
        ];
    }
}
