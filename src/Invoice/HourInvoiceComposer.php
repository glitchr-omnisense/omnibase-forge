<?php

namespace Base\Forge\Invoice;

use Base\Forge\Entity\HourInvoice;
use Base\Forge\Entity\Project;
use Base\Forge\Entity\TimeEntry;
use Base\Marketplace\Entity\Store;
use Base\Marketplace\Invoice\InvoiceComposer;
use Base\Marketplace\Model\InvoiceSource;
use Base\Marketplace\Pricing\ReverseCharge;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What an invoice of hours is made from: omnibase/marketplace's InvoiceSource
 * - the Partner as buyer (InvoiceComposer::buyerOf()), the lines and the
 * totals, the services completed at the period's end, sold by the support
 * store - which marketplace's Invoices::compose() / issueFor() turn into an
 * Invoice, the seller and every mention written from its settings
 * (marketplace.invoice.*). One line per project: its time over the period, in
 * hours (four decimals), at its hourly rate before VAT, the period given on
 * the line (the invoice's billing period, BT-73/74 in its Factur-X).
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
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceComposer $invoiceComposer,
        private readonly ?TranslatorInterface $translator = null,
        #[Autowire('%forge.support_store%')] private readonly string $storeSlug = 'support',
        #[Autowire('%forge.invoicing.vat_exemption%')] private readonly ?string $vatExemption = null,
    ) {
    }

    /**
     * The draft as marketplace issues it, and the line that bills each of its entries.
     *
     * @return array{source: InvoiceSource, line_of: array<int, string>} line_of: by the entry's id (spl_object_id when not stored)
     */
    public function source(HourInvoice $draft): array
    {
        $client = $draft->getClient() ?? throw new \LogicException('An invoice of hours is for a Partner.');
        $store = $this->entityManager->getRepository(Store::class)->findOneBy(['slug' => $this->storeSlug]);
        $buyer = $this->invoiceComposer->buyerOf($client, method_exists($client, 'getAddress') ? $client->getAddress(0) : null);
        $exemption = $this->exemption($this->invoiceComposer->sellerFor($store), $buyer);
        ['lines' => $lines, 'totals' => $totals, 'line_of' => $lineOf] = $this->amounts($draft, array_values($draft->getEntries()->toArray()), $exemption);

        return ['line_of' => $lineOf, 'source' => new InvoiceSource(
            buyer: $buyer,
            lines: $lines,
            totals: $totals,
            // The date the services were completed: the period's end (CGI ann. II art. 242 nonies A, 7°).
            soldAt: $draft->getPeriodEnd(),
            store: $store,
            operation: 'services',
        )];
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
                // The period the services were rendered in: the invoice's billing period (BT-73/74), printed on its PDF.
                'period' => ['start' => $draft->getPeriodStart()->format('Y-m-d'), 'end' => $draft->getPeriodEnd()->format('Y-m-d')],
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

    /** An invoice is written in its seller's language: French for a French seller. */
    private function locale(): ?string
    {
        return 'FR' === strtoupper((string) ($this->invoiceComposer->sellerFor()['country'] ?: 'FR')) ? 'fr' : null;
    }

    private function trans(string $text, array $parameters, ?string $locale): string
    {
        if (!str_starts_with($text, '@') || null === $this->translator) {
            return strtr($text, $parameters);
        }

        return $this->translator->trans($text, $parameters, null, $locale);
    }
}
