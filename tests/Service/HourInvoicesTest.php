<?php

namespace Tests\Base\Forge\Service;

use Base\Entity\User;
use Base\Forge\Entity\HourCredit;
use Base\Forge\Entity\HourInvoice;
use Base\Forge\Entity\Project;
use Base\Forge\Entity\TimeEntry;
use Base\Forge\Enum\HourInvoiceStatus;
use Base\Forge\Invoice\HourInvoiceComposer;
use Base\Forge\Service\HourInvoices;
use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Pricing\ReverseCharge;
use Base\Marketplace\Security\InvoiceVoter;
use Base\Marketplace\Service\Invoices;
use Symfony\Bundle\FrameworkBundle\Test\TestBrowserToken;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Tests\Base\Forge\ForgeWebTestCase;

/**
 * The forge invoices its hours as omnibase/marketplace's Invoice: a draft
 * gathers what is left to invoice, issuing numbers and freezes it, every
 * hour knows the line that billed it and is never billed twice, a credit
 * note releases the hours for a new invoice - in a host application, on its
 * database.
 */
class HourInvoicesTest extends ForgeWebTestCase
{
    private function hourInvoices(): HourInvoices
    {
        return static::getContainer()->get(HourInvoices::class);
    }

    /** @return array{User, Project, list<TimeEntry>, Project} the Partner, their project at 90 € an hour and its October entries, a project on support hours */
    private function partner(): array
    {
        $client = $this->user('partner');
        $project = (new Project($client, 'Boutique', 'boutique-'.bin2hex(random_bytes(3))))->setHourlyRate(9000);
        $project->setClient($client);
        $entries = [
            new TimeEntry(90, 'Mise en place', new \DateTimeImmutable('2026-10-03')),
            new TimeEntry(45, 'Correctif', new \DateTimeImmutable('2026-10-20')),
        ];
        foreach ($entries as $entry) {
            $project->addTimeEntry($entry);
        }
        // Not this month's, not billable: never on October's invoice.
        $project->addTimeEntry(new TimeEntry(60, 'Septembre', new \DateTimeImmutable('2026-09-30')));
        $project->addTimeEntry((new TimeEntry(30, 'Interne', new \DateTimeImmutable('2026-10-10')))->setBillable(false));
        // A project on support hours: taken off the prepaid hours, never invoiced by the hour.
        $support = new Project($client, 'Support', 'support-'.bin2hex(random_bytes(3)));
        $support->setClient($client);
        $support->addTimeEntry(new TimeEntry(120, 'Maintenance', new \DateTimeImmutable('2026-10-15')));
        $this->em->persist($project);
        $this->em->persist($support);
        $this->em->flush();

        return [$client, $project, $entries, $support];
    }

    public function testAMonthOfHoursIsInvoicedOnceThenReleasedByItsCreditNote(): void
    {
        [$client, $project, $entries, $support] = $this->partner();
        // The project on the time spent takes nothing off the support hours; the other one does.
        self::assertNull($this->em->getRepository(HourCredit::class)->findOneBy(['timeEntry' => $entries[0]]));
        self::assertNotNull($this->em->getRepository(HourCredit::class)->findOneBy(['timeEntry' => $support->getTimeEntries()->first()]));

        $draft = $this->hourInvoices()->draft(new HourInvoice($client, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31')));
        self::assertSame([$entries[0], $entries[1]], array_values($draft->getEntries()->toArray()), 'the month\'s billable hours of the project on the time spent, oldest first');
        self::assertSame(135, $draft->getMinutes());
        self::assertSame($draft, $entries[0]->getHourInvoice(), 'reserved by the draft');
        self::assertFalse($entries[0]->isInvoiced());

        // Nothing is left for another draft of the same month while this one holds them.
        $other = $this->hourInvoices()->draft(new HourInvoice($client, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31')));
        self::assertCount(0, $other->getEntries());
        $this->hourInvoices()->discard($other);

        $invoice = $this->hourInvoices()->issue($draft, new \DateTimeImmutable('2026-11-02 10:00'));
        self::assertMatchesRegularExpression('/^F-2026-\d{6}$/', $invoice->getNumber(), 'numbered in marketplace\'s series');
        self::assertNull($invoice->getOrder());
        self::assertSame(HourInvoiceStatus::ISSUED, $draft->getStatus());
        self::assertSame($invoice, $draft->getInvoice());
        [$line] = $invoice->getLines();
        self::assertSame('1', $line['id']);
        self::assertSame(2.25, $line['quantity'], '2h15 in hours');
        self::assertSame(9000, $line['unit_price']);
        self::assertSame(20250, $line['total']);
        self::assertSame(4050, $invoice->getTotals()['vat'], '20 %');
        self::assertSame(24300, $invoice->getTotal());
        self::assertSame('2026-10-31', $invoice->getSoldAt()?->format('Y-m-d'), 'the services completed at the period\'s end');
        self::assertSame('2026-12-02', $invoice->getDueAt()?->format('Y-m-d'), 'marketplace.invoice.payment_days');
        self::assertContains('Nature des opérations : prestations de services.', $invoice->getMentions());
        self::assertContains('Prestations réalisées du 01/10/2026 au 31/10/2026.', $invoice->getMentions());
        self::assertSame(['1', '1'], [$entries[0]->getInvoiceLine(), $entries[1]->getInvoiceLine()], 'each hour knows the line that billed it');

        // The Partner reads it on marketplace's page; nobody else does.
        $authorization = static::getContainer()->get('security.access.decision_manager');
        self::assertTrue($authorization->decide(new TestBrowserToken($client->getRoles(), $client, 'main'), [InvoiceVoter::VIEW], $invoice));
        $stranger = $this->user('stranger');
        $this->em->flush();
        self::assertFalse($authorization->decide(new TestBrowserToken($stranger->getRoles(), $stranger, 'main'), [InvoiceVoter::VIEW], $invoice));

        // Never invoiced twice: a new draft of the month finds nothing.
        $again = $this->hourInvoices()->draft(new HourInvoice($client, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31')));
        self::assertCount(0, $again->getEntries());
        $this->hourInvoices()->discard($again);

        // Cancelled by a credit note, its hours are released.
        $creditNote = $this->hourInvoices()->credit($draft, 'Taux horaire erroné.', new \DateTimeImmutable('2026-11-03 10:00'));
        self::assertMatchesRegularExpression('/^A-2026-\d{6}$/', $creditNote->getNumber());
        self::assertSame($invoice, $creditNote->getCredited());
        self::assertTrue($invoice->isCancelled());
        self::assertSame(HourInvoiceStatus::CANCELLED, $draft->getStatus());
        self::assertSame($creditNote, $draft->getCreditNote());
        self::assertSame(sprintf('Avoir annulant la facture %s du 02/11/2026. Taux horaire erroné.', $invoice->getNumber()), $creditNote->getMentions()[0]);
        $this->em->clear();
        $first = $this->em->find(TimeEntry::class, $entries[0]->getId());
        self::assertNull($first->getHourInvoice(), 'released, as stored');
        self::assertNull($first->getInvoiceLine());

        // And invoiced again, under the next number.
        $client = $this->em->find($client::class, $client->getId());
        $redo = $this->hourInvoices()->draft(new HourInvoice($client, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31')));
        self::assertSame(135, $redo->getMinutes());
        $reissued = $this->hourInvoices()->issue($redo, new \DateTimeImmutable('2026-11-03 11:00'));
        self::assertSame($invoice->getSequence() + 1, $reissued->getSequence(), 'the next number, without a gap');

        // Its client's page lists them, the cancelled one with its credit note.
        $this->signIn($client);
        $page = $this->request('GET', '/compte/factures');
        self::assertSame(200, $page->getStatusCode(), substr(strip_tags((string) $page->getContent()), 0, 1500));
        $content = (string) $page->getContent();
        self::assertStringContainsString($reissued->getNumber(), $content);
        self::assertStringContainsString($invoice->getNumber(), $content);
        self::assertStringContainsString($creditNote->getNumber(), $content);
    }

    public function testAnIssuedInvoiceIsCancelledFromMarketplacesScreenToo(): void
    {
        [$client, , $entries] = $this->partner();
        $draft = $this->hourInvoices()->draft(new HourInvoice($client, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31')));
        $invoice = $this->hourInvoices()->issue($draft, new \DateTimeImmutable('2026-11-02 10:00'));

        // marketplace's own "credit note" action: Invoices::credit(), knowing nothing of the forge.
        static::getContainer()->get(Invoices::class)->credit($invoice);

        self::assertSame(HourInvoiceStatus::CANCELLED, $draft->getStatus(), 'HourInvoiceCancelListener');
        self::assertNull($entries[0]->getHourInvoice());
        self::assertNull($entries[1]->getInvoiceLine());
        self::assertInstanceOf(Invoice::class, $draft->getCreditNote());
    }

    public function testAnIssuedInvoiceKeepsItsHours(): void
    {
        [$client, , $entries] = $this->partner();
        $draft = $this->hourInvoices()->draft(new HourInvoice($client, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31')));
        $this->hourInvoices()->issue($draft, new \DateTimeImmutable('2026-11-02 10:00'));

        $this->expectException(\LogicException::class);
        $this->hourInvoices()->discard($draft);
    }

    public function testAPeriodicInvoiceCoversOneCalendarMonth(): void
    {
        $validator = static::getContainer()->get(ValidatorInterface::class);
        $client = $this->user('partner');

        self::assertCount(0, $validator->validate(new HourInvoice($client, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'))));
        $across = $validator->validate(new HourInvoice($client, new \DateTimeImmutable('2026-10-15'), new \DateTimeImmutable('2026-11-14')));
        self::assertCount(1, $across);
        self::assertStringContainsString('289', (string) $across->get(0)->getMessage());
    }

    public function testTheVatOfAServiceFollowsItsBuyer(): void
    {
        $composer = static::getContainer()->get(HourInvoiceComposer::class);
        $seller = ['country' => 'FR'];
        $address = fn (string $country) => ['street' => [], 'postcode' => '', 'city' => '', 'country' => $country];

        self::assertNull($composer->exemption($seller, ['business' => false, 'address' => $address('DE')]), 'a private person pays French VAT');
        self::assertNull($composer->exemption($seller, ['business' => true, 'vat_number' => 'FR40303265045', 'address' => $address('FR')]), 'a French business pays it');
        self::assertSame(['category' => 'AE', 'reason' => ReverseCharge::MENTION, 'code' => 'VATEX-EU-AE'], $composer->exemption($seller, ['business' => true, 'vat_number' => 'DE811569869', 'address' => $address('DE')]), 'an EU business reverse-charges it');
        self::assertSame('O', $composer->exemption($seller, ['business' => true, 'vat_number' => null, 'address' => $address('CH')])['category'] ?? null, 'outside the EU: outside French VAT');
        self::assertNull($composer->exemption($seller, ['business' => true, 'vat_number' => null, 'address' => $address('GR')]), 'Greece is in the EU (EL in VIES)');
    }
}
