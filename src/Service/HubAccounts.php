<?php

namespace Base\Forge\Service;

use Base\Entity\User;
use Base\Forge\Entity\HubToken;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The accounts the hub clients act for (plan item 16.1): the device flow (DeviceLinks) links an
 * editor or a desktop hub to its user and hands it a token of the account, "gla_" and 32
 * characters of Crockford base32 as a licence key; the client sends it as a Bearer token to buy
 * and to manage its payment methods. Only the token's hash is stored.
 */
class HubAccounts
{
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
        if (null === $authorization || !preg_match('/^Bearer\s+(gla_[0-9A-HJKMNP-TV-Z]{4}(?:-[0-9A-HJKMNP-TV-Z]{4}){7})\s*$/', $authorization, $match)) {
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

        return $token->getUser();
    }
}
