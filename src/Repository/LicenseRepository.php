<?php

namespace Base\Forge\Repository;

use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\Finders;
use Base\Database\Repository\Repositories;
use Base\Entity\User;
use Base\Forge\Entity\License;
use Base\Forge\Entity\LicenseSeat;
use Base\Forge\Entity\Software;
use Base\Forge\Enum\LicenseStatus;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method License|null find($id, $lockMode = null, $lockVersion = null)
 * @method License|null findOneBy(array $criteria, array $orderBy = null)
 * @method License[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class LicenseRepository extends ServiceEntityRepository
{
    public function __construct(private readonly Repositories $repositories, Finders $finders, ?string $entityName = null)
    {
        parent::__construct($finders, $entityName);
    }

    /** @return list<License> newest first */
    public function findOwnedBy(User $user): array
    {
        return $this->findBy(['owner' => Key::of($user), self::withSoftware()], ['createdAt' => 'DESC']);
    }

    /**
     * @return list<License> the licences a seat of which that user holds -
     *                       linked to them, or given to their e-mail once verified
     */
    public function findHeldBy(User $user, ?Software $software = null): array
    {
        // A seat linked to the user, or - once their e-mail is verified - one given to that e-mail and linked to no one.
        $linked = Criterion::of('holders.user', $user);
        $holder = $user->isVerified()
            ? Group::of(Parser::SEPARATOR_OR, $linked, Group::of(
                Parser::SEPARATOR_AND,
                Criterion::of('holders.user', null, Parser::OPTION_NULL),
                Criterion::of('holders.email', mb_strtolower((string) $user->getEmail())),
            ))
            : Group::of(Parser::SEPARATOR_AND, $linked);

        return $this->findBy(
            [$holder, self::withSoftware()] + ($software ? ['software' => Key::of($software)] : []),
            ['createdAt' => 'DESC'],
        );
    }

    /** @return list<License> the user's valid licences for that software - a seat of which they hold -, best first */
    public function findValidFor(User $user, Software $software): array
    {
        $licenses = array_values(array_filter($this->findHeldBy($user, $software), fn (License $license) => $license->isValid()));
        usort($licenses, fn (License $a, License $b) => ($b->getUpdatesUntil()?->getTimestamp() ?? PHP_INT_MAX) <=> ($a->getUpdatesUntil()?->getTimestamp() ?? PHP_INT_MAX));

        return $licenses;
    }

    /** The seat a key was made for. */
    public function findSeatByKey(string $key): ?LicenseSeat
    {
        return $this->repositories->of(LicenseSeat::class)->findOneBy(['keyHash' => hash('sha256', $key)]);
    }

    /** @return list<License> the valid licences a seat of which is given to that e-mail */
    public function findValidByEmail(string $email): array
    {
        $licenses = $this->findBy([
            'holders.email' => mb_strtolower(trim($email)),
            'status' => LicenseStatus::ACTIVE,
            self::withSoftware(),
        ]);

        return array_values(array_filter($licenses, fn (License $license) => $license->isValid()));
    }

    /** @return list<License> active ones whose expiry has passed */
    public function findLapsed(\DateTimeImmutable $at): array
    {
        return $this->findBy([
            'status' => LicenseStatus::ACTIVE,
            Group::of(Parser::SEPARATOR_AND, Criterion::of('expiresAt', $at, Parser::OPTION_LOWER_EQUAL)),
        ]);
    }

    /**
     * The licences whose software reads: what an inner join of it said (a
     * software the persistence hides - its trash - hid the licence).
     */
    private static function withSoftware(): Group
    {
        return Group::of(Parser::SEPARATOR_AND, Criterion::of('software.id', null, Parser::OPTION_NOT_NULL));
    }
}
