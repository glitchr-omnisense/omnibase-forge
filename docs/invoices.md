---
title: Invoices of hours
order: 38
---

# Invoices of hours

A project invoiced on the time spent (`Project::$hourlyRate`, cents before VAT)
is not taken off its client's support hours: its time is invoiced, month by
month, to the Partner (the project's client), as an **omnibase/marketplace
`Invoice`** - the same entity, the same sequence without a gap, the same PDF and
Factur-X, sent the same way as the shop's invoices. The forge keeps only what
an order would otherwise say: which hours, at which rate.

| Here | From omnibase/marketplace |
|---|---|
| `Entity\HourInvoice` (table `forge_hour_invoice`): the draft, then the record of the issued invoice and its credit note | `Entity\Invoice` (order `NULL`), never edited once issued |
| `TimeEntry::$hourInvoice`, `$invoiceLine`: the line that billed an hour | `Invoice::getLines()` (`id`, `quantity` in hours, `unit_price` the rate, `unit` HUR) |
| `Invoice\HourInvoiceComposer`: seller, buyer, lines, totals, mentions | the same shape as `Invoice\InvoiceComposer` writes from an order, the settings `marketplace.invoice.*` and the wording `@marketplace.invoice.mention.*` |
| `Service\HourInvoices`: `draft()`, `discard()`, `preview()`, `issue()`, `send()`, `markPaid()`, `credit()`, `lifecycle()` | `Invoice\InvoiceNumbering::issue()` (symfony/lock), `Service\Invoices::send()`, `credit()` / `reissue()`, `pdf()`, `lifecycle()`, `guidance()` |
| `EventListener\HourInvoiceCancelListener` | any credit note - the forge's screen or marketplace's - releases the hours |
| `Security\Voter\HourInvoiceVoter` | the Partner reads the PDF on `/factures/{number}` (`marketplace_invoice`) |

## The flow

1. **Draft.** The back office's "Invoices of hours" (`HourInvoiceCrudController`):
   a Partner, a period within one calendar month (last month by default), a VAT
   rate (`forge.invoicing.vat_rate`). Saving gathers the time left to invoice:
   billable entries of the Partner's projects with an hourly rate, logged in the
   period, not reserved nor invoiced, never debited from support hours. The
   draft reserves them; its page shows the lines as they would be issued, and
   the entries under each. Edited, it gathers again; deleted, it releases them.
2. **Issue.** Numbered in marketplace's series (`marketplace.invoice.prefix`,
   `F-2026-000042`), its seller, buyer, lines, totals and mentions frozen; each
   time entry gets the line that billed it. A second draft of the same month
   finds nothing: an hour is never invoiced twice.
3. **Send.** `Invoices::send()`: through omnisense/omnibill's gateway when the
   site has it (`marketplace.invoice.gateway`: e-mail, or an approved platform
   for a company established in France), its lifecycle then shown on the
   invoice's page; marketplace's own e-mail otherwise.
4. **Paid**, or **cancelled by a credit note** (`A-2026-…`, "Avoir annulant la
   facture …"): marketplace's `reissue()` rule - an issued invoice is never
   edited -; an invoice of hours has no order to issue again from, so its hours
   are released and a new draft invoices them.

The Partner sees their issued invoices, their state and their credit notes at
`/compte/factures` (`forge_account_invoices`); drafts are never shown.

## The law it follows (France)

- **Numbering**: marketplace's, one sequence without a gap per series and year
  (CGI ann. II art. 242 nonies A, 1°).
- **When**: a periodic invoice for the services of one calendar month, issued by
  its end (CGI art. 289, I-3; BOI-TVA-DECLA-30-20-10-40 §80-100) - hence the
  period within one month (`HourInvoice::validatePeriod()`). The completion date
  of the services is the period's end (`Invoice::getSoldAt()`), the period is
  printed ("Prestations réalisées du … au …").
- **Mentions**: marketplace's (service-public F31808), with the nature of the
  operations set to services, the option for VAT on debits
  (`marketplace.invoice.vat_on_debits`), the due date (`payment_days`), and, to
  a business, the late-payment penalties and the 40 € flat indemnity (code de
  commerce L441-9, L441-10, D441-5).
- **VAT of a service** (CGI art. 259): to a business established in another EU
  country, its VAT number given, reverse-charged ("Autoliquidation", art.
  283-2, category AE); to a business established outside the EU, outside French
  VAT (art. 259-1, category O); a seller under the franchise prints
  `forge.invoicing.vat_exemption` (art. 293 B, category E); otherwise the
  draft's rate.

Not done here: the e-reporting of the services' payment data (B2C and
international B2B: marketplace's `EReporting` reads paid orders, which an
invoice of hours has not), the billing period's own Factur-X fields (BT-73/74)
and a line unit (`HUR`) in marketplace's Factur-X writer (it writes `H87`).

## Configuration

```yaml
forge:
    invoicing:
        vat_rate: 20.0          # a new draft's rate
        vat_exemption: ~        # "TVA non applicable, art. 293 B du CGI" under the franchise
marketplace:
    invoice:
        seller: { name: 'Glitch Art', siren: '…', vat_number: 'FR…', … }
        payment_days: 30
```

## Migration

A site that has omnibase/forge adds, by a migration: the table
`forge_hour_invoice`, the columns `forge_time_entry.hourInvoice_id` (nullable,
`ON DELETE SET NULL`) and `invoiceLine`, and the project's `hourlyRate`
(nullable). Nothing changes in marketplace's tables.
