<?php

namespace Base\Forge\Repository;

use Base\Database\Repository\Finder\Aggregate;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\Finder\Key;
use Base\Entity\User;
use Base\Forge\Entity\HourCredit;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method HourCredit|null find($id, $lockMode = null, $lockVersion = null)
 * @method HourCredit|null findOneBy(array $criteria, array $orderBy = null)
 * @method HourCredit[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 * @method mixed           sumOfMinutesBy(array $criteria)
 */
class HourCreditRepository extends ServiceEntityRepository
{
    /** Minutes left: everything bought minus everything logged (0 without a line: SUM of no row is null). */
    public function balance(User $user): int
    {
        return (int) $this->sumOfMinutesBy(['user' => Key::of($user)]);
    }

    /** @return list<HourCredit> newest first */
    public function history(User $user, int $limit = 50): array
    {
        return $this->findBy(['user' => Key::of($user)], ['createdAt' => 'DESC', 'id' => 'DESC'], $limit);
    }

    /** @return array<int, int> user id => balance, for the users below $minutes who once bought hours */
    public function lowBalances(int $minutes): array
    {
        $balances = [];
        foreach ($this->findValuesBy(['user', 'balance' => Aggregate::sum('minutes')], having: [
            Group::of(Parser::SEPARATOR_AND, Criterion::of('balance', $minutes, Parser::OPTION_LOWER)),
        ]) as $row) {
            $balances[(int) $row['user']] = (int) $row['balance'];
        }

        return $balances;
    }

    public function hasOrder(string $reference): bool
    {
        return null !== $this->findOneBy(['orderReference' => $reference]);
    }
}
