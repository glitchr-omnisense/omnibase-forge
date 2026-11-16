<?php

namespace Tests\Base\Forge\Persistence;

use Base\Contract\Locales;
use Base\Contract\Runtime;
use Base\Testing\Engines;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;
use App\Entity\User; // the host's user, Base\Entity\User where it has none (ClassAliases, registered by Engines::of())
use Base\Forge\Entity\HourCredit;
use Base\Forge\Entity\Project;
use Base\Forge\Entity\Quote;
use Base\Forge\Entity\TimeEntry;
use Base\Forge\Enum\CreditReason;
use Base\Forge\Repository\HourCreditRepository;
use Base\Forge\Repository\TimeEntryRepository;
use Base\Forge\Repository\QuoteRepository;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * forge's queries once written per persistence, now written once in the language, on both engines:
 * the hours left to invoice, the low balances, the quotes' numbering - the same answers on the same rows.
 */
final class StatementsParityTest extends TestCase
{
    private static Engines $engines;

    public static function backends(): array
    {
        return Engines::backends();
    }

    /** @var array<string, int> */
    private static array $ids = [];

    public static function setUpBeforeClass(): void
    {
        // The entities' constructors read the runtime's locale (User::setLocale()): one, as a host gives it.
        if (!Runtime::has(Locales::class)) {
            Runtime::set(Locales::class, new class implements Locales {
                public function getLocale(): string { return 'fr'; }
                public function getDefaultLocale(): string { return 'fr'; }
                public function getAvailableLocales(): array { return ['fr']; }
                public function getTimezone(): string { return 'UTC'; }
            });
        }
        self::$engines = Engines::of([TimeEntry::class, HourCredit::class, Quote::class, \Base\Marketplace\Entity\Product::class], ['project_dir' => \dirname(__DIR__, 2), 'enumerations' => [\dirname((string) (new \ReflectionClass(\Base\Marketplace\Entity\Product::class))->getFileName(), 2).'/Enum'], 'resolve_target_entities' => ['Base\\Marketplace\\Model\\MerchantInterface' => User::class]]);
        $seed = [];
        $client = self::user('client@example.org');
        $other = self::user('other@example.org');
        $billed = (new Project($client, 'Billed', 'billed'))->setHourlyRate(8000);
        $flat = (new Project($client, 'Flat', 'flat'))->setHourlyRate(null);
        $elsewhere = (new Project($other, 'Elsewhere', 'elsewhere'))->setHourlyRate(8000);
        $entry = static fn (Project $project, int $minutes, string $on, bool $billable = true) => (new TimeEntry($minutes, $on, new \DateTimeImmutable($on)))->setProject($project)->setBillable($billable);
        $entries = [
            'jan-1' => $entry($billed, 60, '2026-01-01'),
            'jan-5' => $entry($billed, 30, '2026-01-05'),
            'covered' => $entry($billed, 45, '2026-01-06'),
            'unbillable' => $entry($billed, 20, '2026-01-07', false),
            'empty' => $entry($billed, 0, '2026-01-08'),
            'flat' => $entry($flat, 60, '2026-01-09'),
            'other' => $entry($elsewhere, 60, '2026-01-10'),
            'feb' => $entry($billed, 60, '2026-02-01'),
        ];
        $credits = [
            new HourCredit($client, 120, CreditReason::PURCHASE),
            (new HourCredit($client, -45, CreditReason::TIME))->setTimeEntry($entries['covered']),
            new HourCredit($other, 30, CreditReason::PURCHASE),
            new HourCredit($other, -10, CreditReason::TIME),
        ];
        foreach ([$client, $other, $billed, $flat, $elsewhere, ...array_values($entries), ...$credits] as $entity) {
            $seed[] = $entity;
        }
        self::$engines->seed(...$seed);
        self::$ids = ['client' => (int) $client->getId(), 'other' => (int) $other->getId()];
    }

    /** @dataProvider backends */
    #[DataProvider('backends')]
    public function testTheTimeLeftToInvoice(string $backend): void
    {
        $finders = self::$engines->finders($backend);
        $client = $finders->of(User::class)->find(self::$ids['client']);
        $entries = (new TimeEntryRepository($finders))->invoiceable($client, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));

        self::assertSame(['2026-01-01', '2026-01-05'], array_map(static fn (TimeEntry $e) => $e->getSpentOn()->format('Y-m-d'), $entries), 'billable, timed, on a project billing time, in the dates, no support-hours line; oldest first');
    }

    /** @dataProvider backends */
    #[DataProvider('backends')]
    public function testTheLowBalances(string $backend): void
    {
        $credits = (new HourCreditRepository(self::$engines->finders($backend)));

        self::assertSame([self::$ids['other'] => 20], $credits->lowBalances(60));
        self::assertSame([self::$ids['client'] => 75, self::$ids['other'] => 20], $credits->lowBalances(100) + [], 'every balance below');
        self::assertSame([], $credits->lowBalances(0));
    }

    /** @dataProvider backends */
    #[DataProvider('backends')]
    public function testTheQuotesAreNumberedInTurn(string $backend): void
    {
        $quotes = (new QuoteRepository(self::$engines->finders($backend)));
        $year = new \DateTimeImmutable('2030-06-01');
        $first = new Quote();
        $quotes->saveNumbered($first, $year);
        $second = new Quote();
        $quotes->saveNumbered($second, $year);

        // The doctrine case runs first: the eloquent one goes on from its numbers, on the same table.
        $start = 'doctrine' === $backend ? 1 : 3;
        self::assertSame(sprintf('Q-2030-%04d', $start), $first->getReference());
        self::assertSame(sprintf('Q-2030-%04d', $start + 1), $second->getReference());
    }

    /** A user as the host builds one: its own User may require more than an e-mail (a username, written when it has one). */
    private static function user(string $email): User
    {
        $user = new User($email);
        $accessor = PropertyAccess::createPropertyAccessor();
        if ($accessor->isWritable($user, 'username')) {
            $accessor->setValue($user, 'username', strstr($email, '@', true));
        }

        return $user;
    }
}
