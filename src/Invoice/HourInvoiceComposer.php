<?php

namespace Base\Forge\Invoice;

use Base\Entity\User;
use Base\Forge\Entity\HourInvoice;
use Base\Forge\Entity\Project;
use Base\Forge\Entity\TimeEntry;
use Base\Marketplace\Entity\Store;
use Base\Marketplace\Model\VatCustomerInterface;
use Base\Marketplace\Pricing\ReverseCharge;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What an invoice of hours says, written the day it is issued: the seller,
 * the buyer, the lines, the totals and the mentions - in the very shape
 * omnibase/marketplace's Invoice keeps them (its PDF, its Factur-X and its
 * transmission read nothing else), from the same settings
 * (marketplace.invoice.*) and the same wording (@marketplace.invoice.mention.*).
 *
 * marketplace's own InvoiceComposer writes them from an Order; an invoice of
 * hours has none, hence this one. One line per project: its time over the
 * period, in hours (four decimals), at its hourly rate before VAT.
 *
 * The VAT, a service's (CGI art. 259 to 259 D):
 *   - the seller exempt (forge.invoicing.vat_exemption, the franchise of
 *     art. 293 B): no VAT, the mention;
 *   - a business established in another EU country, its VAT number given:
 *     taxed where it is (art. 259-1), reverse-charged by it (art. 283-2),
 *     "Autoliquidation" printed (art. 242 nonies A, 13° of annex II);
 *   - a business established outside the EU: taxed where it is (art. 259-1),
 *     outside French VAT;
 *   - anyone else: the draft's rate (20 % by default, art. 278).
 */
final class HourInvoiceComposer
{
    /** @param array<string, mixed> $seller marketplace.invoice.seller */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?TranslatorInterface $translator = null,
        #[Autowire('%marketplace.invoice.seller%')] private readonly array $seller = [],
        #[Autowire('%marketplace.invoice.vat_on_debits%')] private readonly bool $vatOnDebits = false,
        #[Autowire('%marketplace.invoice.late_penalties%')] private readonly ?string $latePenalties = null,
        #[Autowire('%marketplace.invoice.discount%')] private readonly ?string $discount = null,
        #[Autowire('%marketplace.invoice.mentions%')] private readonly array $mentions = [],
        #[Autowire('%forge.support_store%')] private readonly string $storeSlug = 'support',
        #[Autowire('%forge.invoicing.vat_exemption%')] private readonly ?string $vatExemption = null,
    ) {
    }

    /** @return array<string, mixed> the seller that day: marketplace.invoice.seller, the support store's VAT number over it */
    public function seller(): array
    {
        $seller = $this->seller + ['name' => '', 'street' => [], 'postcode' => '', 'city' => '', 'country' => 'FR'];
        $seller['street'] = array_values(array_filter((array) $seller['street']));
        $store = $this->entityManager->getRepository(Store::class)->findOneBy(['slug' => $this->storeSlug]);
        $seller['vat_number'] = $store?->getVatNumber() ?? ($seller['vat_number'] ?? null);
        $seller['store'] = null !== $store ? (string) $store : null;
        if ('' === trim((string) $seller['name'])) {
            $seller['name'] = (string) $store;
        }

        return $seller;
    }

    /** @return array<string, mixed> the buyer that day: the Partner's account, its first address */
    public function buyer(User $client): array
    {
        $address = method_exists($client, 'getAddress') ? $client->getAddress(0) : null;
        $company = self::call($client, 'getCompanyName');
        $buyer = [
            'name' => $company ?? self::call($address, 'getName') ?? self::person($client),
            'company' => null !== $company,
            'email' => $client->getEmail(),
            'address' => [
                'street' => array_values(array_filter([self::call($address, 'getStreetAddress'), self::call($address, 'getAdditional')])),
                'postcode' => (string) self::call($address, 'getZipCode'),
                'city' => (string) self::call($address, 'getCity'),
                'country' => strtoupper((string) (self::call($address, 'getCountry') ?? '')),
            ],
            'siren' => self::call($client, 'getSiren'),
            'siret' => self::call($client, 'getSiret'),
            'vat_number' => $client instanceof VatCustomerInterface ? (self::blank($client->getVatNumber())) : self::call($client, 'getVatNumber'),
            'delivery' => null,
        ];
        $buyer['business'] = $buyer['company'] || null !== $buyer['siren'] || null !== $buyer['siret'] || null !== $buyer['vat_number'];

        return $buyer;
    }

    /**
     * The VAT exemption of a buyer, as marketplace's InvoiceComposer::exemption() writes it; null: VAT is charged.
     *
     * @param array<string, mixed> $seller
     * @param array<string, mixed> $buyer
     *
     * @return array{category: string, reason: string, code: ?string}|null
     */
    public function exemption(array $seller, array $buyer): ?array
    {
        if (null !== $this->vatExemption && '' !== trim($this->vatExemption)) {
            return ['category' => 'E', 'reason' => trim($this->vatExemption), 'code' => str_contains($this->vatExemption, '293 B') ? 'VATEX-FR-FRANCHISE' : null];
        }
        if (!($buyer['business'] ?? false)) {
            return null;
        }
        $home = strtoupper((string) (($seller['country'] ?? '') ?: 'FR'));
        $vatCountry = strtoupper(substr((string) ($buyer['vat_number'] ?? ''), 0, 2));
        $country = strtoupper((string) ($buyer['address']['country'] ?? '')) ?: $vatCountry;
        // Another EU country, its VAT number given: the buyer accounts for the VAT (CGI art. 283-2).
        if ('' !== $vatCountry && \in_array($vatCountry, ReverseCharge::EU, true) && $vatCountry !== $home && 'FR' !== $vatCountry) {
            return ['category' => 'AE', 'reason' => ReverseCharge::MENTION, 'code' => 'VATEX-EU-AE'];
        }
        // Established outside the EU: the service is taxed where the buyer is (CGI art. 259-1).
        if ('' !== $country && !\in_array($country, ReverseCharge::EU, true) && 'GR' !== $country) {
            return ['category' => 'O', 'reason' => $this->trans('@forge.invoice.mention.outside_eu', [], $this->locale()), 'code' => 'VATEX-EU-O'];
        }

        return null;
    }

    /**
     * The lines - one per project - and the totals, by VAT rate.
     *
     * @param list<TimeEntry>                                            $entries
     * @param array{category: string, reason: string, code: ?string}|null $exemption
     *
     * @return array{lines: list<array<string, mixed>>, totals: array<string, mixed>, line_of: array<int, string>} line_of: the line of each entry, by its id (spl_object_id when not stored)
     */
    public function amounts(HourInvoice $draft, array $entries, ?array $exemption): array
    {
        $rate = null !== $exemption ? 0.0 : round($draft->getVatRate(), 3);
        $category = $exemption['category'] ?? ($rate > 0 ? 'S' : 'Z');

        /** @var array<int, array{project: Project, minutes: int, entries: list<TimeEntry>}> $groups */
        $groups = [];
        foreach ($entries as $entry) {
            $project = $entry->getProject() ?? throw new \LogicException('A time entry is invoiced with its project.');
            $groups[spl_object_id($project)] ??= ['project' => $project, 'minutes' => 0, 'entries' => []];
            $groups[spl_object_id($project)]['minutes'] += $entry->getMinutes();
            $groups[spl_object_id($project)]['entries'][] = $entry;
        }

        $locale = $this->locale();
        $lines = [];
        $lineOf = [];
        foreach ($groups as $group) {
            $id = (string) (\count($lines) + 1);
            $unit = (int) $group['project']->getHourlyRate();
            // The amount of the minutes worked, to the cent; the hours to four decimals (Factur-X's
            // quantity), so that hours x rate gives it back: 4h25 is 4,4167 h, never 4,42 h billed.
            $hours = round($group['minutes'] / 60, 4);
            $total = (int) round($group['minutes'] * $unit / 60);
            $lines[] = [
                'id' => $id,
                'label' => $this->trans('@forge.invoice.line', [
                    '{project}' => $group['project']->getName(),
                    '{from}' => $draft->getPeriodStart()->format('d/m/Y'),
                    '{to}' => $draft->getPeriodEnd()->format('d/m/Y'),
                    '{time}' => TimeEntry::formatMinutes($group['minutes']),
                ], $locale),
                'reference' => $group['project']->getSlug(),
                'quantity' => $hours,
                'unit' => 'HUR',
                'minutes' => $group['minutes'],
                'unit_price' => $unit,
                'gross_unit_price' => $unit,
                'discount' => 0,
                'rate' => $rate,
                'category' => $category,
                'total' => $total,
                'vat' => 0,
                'total_with_vat' => $total,
            ];
            foreach ($group['entries'] as $entry) {
                $lineOf[$entry->getId() ?? spl_object_id($entry)] = $id;
            }
        }

        // The VAT on the base of the rate, rounded once (EN 16931 BR-CO-17); the lines share it, the last one taking the cent left.
        $base = array_sum(array_column($lines, 'total'));
        $vat = (int) round($base * $rate / 100);
        $left = $vat;
        foreach ($lines as $i => &$line) {
            $line['vat'] = $i === \count($lines) - 1 ? $left : (int) round($line['total'] * $rate / 100);
            $left -= $line['vat'];
            $line['total_with_vat'] = $line['total'] + $line['vat'];
        }
        unset($line);

        return ['lines' => $lines, 'line_of' => $lineOf, 'totals' => [
            'lines' => $base,
            'charges' => [],
            'allowances' => [],
            'charges_total' => 0,
            'allowances_total' => 0,
            'vat_breakdown' => [['rate' => $rate, 'category' => $category, 'base' => $base, 'vat' => $vat]],
            'total_without_vat' => $base,
            'vat' => $vat,
            'total' => $base + $vat,
            'paid' => 0,
            'due' => $base + $vat,
            'exemption' => $exemption,
        ]];
    }

    /**
     * The mentions it prints, as marketplace's invoices word them: the VAT
     * exemption, the nature of the operations (services), the period the
     * services were rendered in, the option for VAT on debits, the due date,
     * the discount for early payment and - to a business - the penalties for
     * late payment and the flat indemnity (code de commerce L441-9, L441-10,
     * D441-5), then the seller's own.
     *
     * @param array<string, mixed>                                        $buyer
     * @param array{category: string, reason: string, code: ?string}|null $exemption
     *
     * @return list<string>
     */
    public function mentions(HourInvoice $draft, array $buyer, ?array $exemption, \DateTimeImmutable $dueAt): array
    {
        $locale = $this->locale();
        $mentions = [];
        if (null !== $exemption) {
            $mentions[] = $exemption['reason'];
        }
        $mentions[] = $this->trans('@marketplace.invoice.mention.operation.services', [], $locale);
        $mentions[] = $this->trans('@forge.invoice.mention.period', ['{from}' => $draft->getPeriodStart()->format('d/m/Y'), '{to}' => $draft->getPeriodEnd()->format('d/m/Y')], $locale);
        if ($this->vatOnDebits) {
            $mentions[] = $this->trans('@marketplace.invoice.mention.vat_on_debits', [], $locale);
        }
        $mentions[] = $this->trans('@marketplace.invoice.mention.due', ['{date}' => $dueAt->format('d/m/Y')], $locale);
        if (null !== $this->discount && '' !== $this->discount) {
            $mentions[] = $this->trans($this->discount, [], $locale);
        }
        if (($buyer['business'] ?? false) && null !== $this->latePenalties && '' !== $this->latePenalties) {
            $mentions[] = $this->trans($this->latePenalties, [], $locale);
        }
        foreach ($this->mentions as $mention) {
            $mentions[] = $this->trans((string) $mention, [], $locale);
        }

        return array_values(array_filter($mentions, fn ($m) => '' !== trim((string) $m)));
    }

    /** "Avoir annulant la facture F-2026-000042 du 01/11/2026.": what a credit note says of the invoice it cancels. */
    public function creditMention(\Base\Marketplace\Entity\Invoice $invoice): string
    {
        return $this->trans('@marketplace.invoice.mention.credit', ['{number}' => $invoice->getNumber(), '{date}' => $invoice->getIssuedAt()->format('d/m/Y')], $this->locale());
    }

    /** An invoice is written in its seller's language: French for a French seller. */
    private function locale(): ?string
    {
        return 'FR' === strtoupper((string) (($this->seller['country'] ?? '') ?: 'FR')) ? 'fr' : null;
    }

    private function trans(string $text, array $parameters, ?string $locale): string
    {
        if (!str_starts_with($text, '@') || null === $this->translator) {
            return strtr($text, $parameters);
        }

        return $this->translator->trans($text, $parameters, null, $locale);
    }

    private static function person(User $client): ?string
    {
        $name = trim(((string) self::call($client, 'getFirstname')).' '.((string) self::call($client, 'getLastname')));

        return '' !== $name ? $name : (self::call($client, 'getUsername') ?? $client->getEmail());
    }

    private static function blank(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }

    private static function call(?object $object, string $method): ?string
    {
        if (null === $object || !method_exists($object, $method)) {
            return null;
        }
        $value = $object->$method();

        return null === $value || '' === trim((string) $value) ? null : trim((string) $value);
    }
}
