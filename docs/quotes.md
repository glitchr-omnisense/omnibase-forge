---
title: Quotes
order: 37
---

# Quotes

A client asks for a quote from the site (`/support/devis`), the studio prices
it in the back office - lines of hours at a rate, a discount, a date - and
sends it (`/admin/forge/quote/{id}/send`); the client reads it at
`/devis/{token}`, accepts it, and pays a one-off `HourPack` holding its hours.
Paid, the hours are credited and the quote marked paid
(`EventSubscriber\OrderPaidSubscriber`).

## What is omnibase/marketplace's

Since the marketplace's business quotes, what every quote is lives there, and
the forge keeps only its hours:

| Here | From omnibase/marketplace |
|---|---|
| `Entity\Quote` (table `forge_quote`, unchanged) | extends `Base\Marketplace\Entity\Quote\AbstractQuote`: client, company check, status, discount, validity, token, `isAcceptable()`, `accept()`, `markPaid()` |
| `Entity\QuoteLine` (minutes at an hourly rate) | - |
| `Enum\QuoteStatus` | an alias of `Base\Marketplace\Enum\QuoteStatus` (requested, draft, sent, accepted, paid, declined) |
| `Repository\QuoteRepository` | `Base\Marketplace\Quote\QuoteRepositoryTrait`: `saveNumbered()` (Q-2026-0001), `findForClient()`, `pipeline()` |
| `Service\QuoteToOrder` | extends `Base\Marketplace\Service\QuoteToOrder`: a forge quote becomes one `HourPack` of the support store in the client's cart |
| `Service\QuoteStatusGuard` | delegates to `Base\Marketplace\Service\QuoteStatusGuard` |
| the request form | `Base\Marketplace\Form\QuoteRequestType` with `['trade' => false]` and `Base\Marketplace\Model\QuoteRequest` (the forge's own copies are gone) |

`hasSomethingToSell()` is the hours: a quote without any cannot be accepted.

## Upgrading

Nothing in the database: `forge_quote` and `forge_quote_line` keep their
columns (the schema generated before and after is the same). An application
using `Base\Forge\Form\QuoteRequestType` or `Base\Forge\Model\QuoteRequest`
directly uses the marketplace's instead; `Base\Forge\Enum\QuoteStatus` keeps
working (an alias).
