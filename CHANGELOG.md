# Changelog

## [Unreleased]

## [1.2.1] - 2026-11-14

### Fixed

- The one-click purchases' statements in the language asked: with omnibase/marketplace 1.1.1 (now required), the
  quote's `?locale=fr` gives, records and confirms the French texts, and without `?locale=` the texts are in the
  host's own language (`kernel.default_locale`: the clients send no Accept-Language), no longer always English.
  `consent.locale` is the language the texts are in (docs/hub.md).
- `HubPurchaseTest` passes inside a host that names its terms (`marketplace.consent.terms_route`): the statements
  expected are the host's own (`terms` first when it names them). New
  `testTheStatementsAreInTheLanguageAskedElseTheHostsAndRecordedSo`.
- `RegistrySnapshotTest`'s recorded hub manifest has `checkout.account_tokens` (added in 1.1.0): the test failed in
  the bundle's own harness too since 1.1.0.

### Changed

- Requires `omnibase/marketplace` ^1.1.1.

## [1.2.0] - 2026-11-14

### Added

- A saved payment method's setup is completed when its buyer is back (omnisense/omnitrade 1.2's
  `CompleteMethodSetup`, asked whenever the gateway supports it - no provider-specific code): a read at Stripe, the
  approved setup turned into a saved method at PayPal (omnitrade/paypal 1.2, which one-click purchases can now use).
  - `Entity\PaymentSetup` (table `forge_payment_setup`): the provider's setup and customer, under a random token,
    pending until completed (`saved`, `not_saved`).
  - `POST /upm/-/hub/payment-methods` sends the buyer back to `/hub/payment-methods/saved?setup=<token>`, which
    completes the setup and says saved, not saved (given up) or being saved (the provider unreachable); an unknown
    token is 404, a setup that is not the user's customer's is never completed. A return without a token (a setup
    started before 1.2.0) is answered as before.
  - `HubPayments::complete()`; `HubPayments::methods()` completes the user's setups still pending, for three hours
    (the tab closed before the provider sent its buyer back), then gives them up.
  - `HubPurchaseTest`: a Stripe-shaped completion (a read), a PayPal-shaped one (the setup converted), a setup not
    finished, another customer's, one left pending then completed or given up.
- docs/hub.md, "Completing the setup": nothing changes for Forge Store and Forge Hub.

### Changed

- Requires `omnisense/omnitrade` ^1.2 when it is installed (`conflict` with < 1.2; require-dev ^1.2); suggests
  `omnitrade/stripe` or `omnitrade/paypal` ^1.2.
- The test bootstrap reads the suggested packages a host has in vendor/ without installing them too
  (the omnibase harness's checkouts of omnitrade/paypal).

### Schema

- New table `forge_payment_setup`: the host's migration (`make:migration`).

## [1.1.0] - 2026-11-14

### Added

- One-click purchases carry their buyer's statements - the terms of sale accepted, immediate supply asked for and
  the right of withdrawal waived (Code de la consommation L221-28 13°) - in the version of the texts shown
  (docs/hub.md, "The statements"). Prepared for a lawyer's review; omnibase/marketplace's docs/withdrawal.md lists
  the assumptions.
  - `GET /upm/-/hub/quote` gives `consent` (`required`, `version`, `locale`, `terms_url`, `statements[]` with `id`,
    `text`, `hash`, `url`) and takes an optional `locale`.
  - `POST /upm/-/hub/purchases` takes `consent` (`version`, `locale`, and each statement listed: `true`). Refused
    without it: `422 consent_required` (`missing`, `consent`); another version: `409 consent_changed` (`consent`).
    Nothing charged, no purchase made. Idempotent replays, `price_changed`, 3-D Secure and the webhook unchanged.
  - Accepted, the statements are recorded on the purchase's order (marketplace's `Entity\Order\Consent`, channel
    `hub`) and repeated in marketplace's order confirmation e-mail once paid.
  - `forge.hub.consent_required` (default true). False: a client that sends none (Forge Store 1.7.0, Forge Hub
    1.5.0) buys as in 1.0, nothing recorded.
  - `LicenseOffer` is digital content (`Withdrawal::DIGITAL_CONTENT`) and `HourPack` a service
    (`Withdrawal::SERVICE`) by default: the shop's checkout asks the matching statement for them too.
  - `HubPurchaseTest`: the statements in the quote, the purchase without them, with one not true, another version,
    accepted (the record, the e-mail), the requirement off, the terms when the host names them.
- A purchase's key again (docs/hub.md, "A purchase's key again"): `POST /upm/-/hub/purchases/<id>/key`, with the
  account's token, owner only, gives a client that lost the answer carrying the key a **new key of the same seat**;
  the seat's previous key stops working at once. Keys are kept as SHA-256 only (`LicenseSeat`), so the key handed
  over cannot be given twice; nothing is stored in clear or encrypted. `404 unknown_purchase` (another account's
  purchase too), `409 purchase_not_paid` (with `status`), `409 license_unavailable`, `409 purchase_in_progress`.
  A paid purchase's body says `"key_handed": true` once its key went out. `HubPurchases::reissueKey()`.
- Linked editors and signing out (docs/hub.md): `GET /upm/-/hub/account-tokens` (each token of the account: `id`,
  `prefix`, `created`, `last_used`, `current`), `DELETE /upm/-/hub/account-tokens/current` (this editor signed out),
  `DELETE /upm/-/hub/account-tokens/{id}` (another one; `404 unknown_account_token`). The manifest's `checkout`
  names `account_tokens`. The account page (`/compte/licences`) lists the linked editors and unlinks one
  (`forge_account_hub_token_revoke`). `HubAccountController`, `HubAccounts::token()`, `tokensOf()`, `revoke()`;
  the request's `_forge_hub_token` beside `_forge_hub_user`.
- `GET /upm/-/hub/licenses` takes the account's token too (`Bearer gla_...`): the licences the account holds a seat
  of, valid ones, with the machines of its seats - the same answer as a seat's key. A token that is not this
  forge's: 401 with the account's realm. The account's token still opens no package.
- `HubAccountTokensTest`; `HubPurchaseTest`: a key lost then asked again (the lost one refused by the registry, the
  new one taken, owner only, a licence revoked), a purchase not paid yet.

### Changed

- Requires `omnibase/marketplace` ^1.1.
- Forge Store 1.7.0 and Forge Hub 1.5.0 get `422 consent_required` on a purchase while `forge.hub.consent_required`
  is on ("The forge refused: consent_required."): their users buy on the site until the clients send the
  statements, or the host turns the requirement off.
- The bundle's classes extend omnibridge/symfony's `AbstractBaseBundle` and `AbstractBaseExtension` by their names
  (`Omnibridge\Symfony\Omnibase\Bundle\*`), no longer by the former `Base\Bundle\*` ones, which omnisense/omnibase
  keeps only as aliases until 6.0. The same classes: nothing changes for an application.

### Schema

- None of the forge's own; omnibase/marketplace 1.1's table `marketplace_order_consent` and the products' column
  `withdrawal`: the host's migration.

## [1.0.1] - 2026-11-14

### Fixed

Tests only, no behaviour change: the suite now passes inside a host application as well as in the package's own CI
and the omnibase harness.

- `ForgeConfigurationTest` names its data provider with PHPUnit's `#[DataProvider]` attribute (the annotation kept for
  PHPUnit 9.6): PHPUnit 12 reads no annotation, so the test ran without its colours and failed.
- `HubPurchaseTest` expects the quote's store name the host configures (`forge.hub.name`, "Forge" by default), not
  "Forge" always.
- `RegistrySnapshotTest` checks the hub manifest's scopes against `forge.hub.scopes` when the host sets them (else
  the database's, as before), and its brand (`name`, `logo`, `color`) against the host's configuration, before
  comparing the manifest with the snapshot recorded with the defaults.

## [1.0.0] - 2026-11-13

### Changed

- Installs with the default minimum-stability: stable requirements only (`git/git-bundle` ^1.0 now, beside
  `omnisense/omnibase` ^5.1, `omnibridge/symfony` ^1.1, `omnisense/omnihub` ^1.0, `omnibase/admin` and
  `omnibase/marketplace` ^1.0, `omnisense/omnistate` and `omnistate/annuaire-entreprises` ^1.0).
- `doctrine/doctrine-bundle` and `symfony/doctrine-bridge` required: the repositories extend DoctrineBundle's
  `ServiceEntityRepository`, the entities use `UniqueEntity`. require-dev: `omnisense/omnitrade` ^1.1.
- CI: glitchr-studio/ci's package workflow (the bundle installed alone, composer validate, PHPUnit, PHPStan against
  its baseline, a consumer install without require-dev) and its release workflow.
- `GithubActionsSourceTest` gives the runtime one locale where no host application does (a project's title is
  translatable), so it runs in the package's own CI.

### Added
- One-click purchases from Forge Store and Forge Hub (plan items 16.2 to 16.4, and 17.3 on the server; docs/hub.md,
  "Buying in one click"), through omnisense/omnitrade 1.1's saved methods and the gateway `forge.hub.payment_gateway`
  names (`stripe` by default): `GET|POST /upm/-/hub/payment-methods` (listed from the provider, saved on its page),
  `DELETE /upm/-/hub/payment-methods/{id}` (only the user's), `GET /upm/-/hub/quote?offer=` (the amount the order
  charges, its currency, the store's name), `POST /upm/-/hub/purchases` with an `Idempotency-Key` (charged
  off-session: paid, `requires_action` with the bank's 3-D Secure page, refused 402; 409 `price_changed` with the price
  now when it is not the amount confirmed; the same key answers the same purchase, 200 `Idempotent-Replayed`), `GET
  /upm/-/hub/purchases/{id}` (the licence key handed over once paid). One purchase of a user at a time under a
  symfony/lock lock (409 `purchase_in_progress` after 15 s). The order is the shop's (`Checkout::settle()`), paid at once
  or by Stripe's `payment_intent.succeeded` webhook, the licence issued by `OrderPaidSubscriber`; a refused one is
  abandoned, not left as the user's cart. Return pages `/hub/payment-methods/saved` and `/hub/purchases/{id}/done`. The
  manifest's `checkout` gains `quote`, `purchases` and `methods`. Entities `HubToken`, `PaymentCustomer` (the
  provider's customer reference: all the forge keeps - never a card number) and `HubPurchase`: **a migration in the
  host** (tables `forge_hub_token`, `forge_payment_customer`, `forge_hub_purchase`). Services `HubAccounts`,
  `HubPayments`, `HubPurchases`, `HubPurchaseCharges`; `HubAccountSubscriber` (401 before the payload is read).
  `HubPurchaseTest`: the whole path on a recorded Stripe, signed webhook in, v2 licence token out and verified with
  the published key. Requires symfony/lock; suggests omnisense/omnitrade ^1.1.
- The device flow links an editor to its user's account (plan item 16.1): `/upm/-/hub/token` answers an
  `account_token` (`gla_...`, only its SHA-256 kept) beside the e-mail, and the licence key only when the user holds a
  seat - a user without a licence can link the editor now, to buy from it. `DeviceLinks::approve()` returns a bool.
  The hub API's errors are JSON (`LicenseApiErrorSubscriber` covers `/upm/-/hub/`).
- `tests/Controller/RegistrySnapshotTest.php`: the registry's and the hub's documents (package documents, search,
  listing, tarball heads, hub manifest, catalogue; a free, a licensed, a draft package and two games, anonymously
  and with a key) compared byte for byte with `tests/Fixtures/registry-snapshot.json`.
- The hub manifest (`/upm/-/hub.json`) publishes the licensing key (plan item 17.1): `licensing` with `algorithm`,
  `publicKey`, `registry` and, after a rotation, `previousKey`, `rotation` (the previous key's Ed25519 signature of
  `"forge-key-rotation:v1:" + publicKey`, as Forge Store checks it) and `previousKeyValidUntil`. A rotation is
  published only when it verifies against the key in use. `/api/licenses/public-key` gives the same, in its own
  names. `HubCatalog::licensing()`, `LicenseToken::rotation()`, `successor()`, `rotationVerifies()`, `fingerprint()`,
  `UpmRegistry::url()`.
- `bin/console forge:license:rotate [--local] [--compromised]`: a new signing key, signed over by the current one,
  sealed in the secrets vault with what the manifest publishes of the change (`FORGE_LICENSE_PREVIOUS_KEY`,
  `FORGE_LICENSE_ROTATION`, `FORGE_LICENSE_PREVIOUS_UNTIL`); `Service\LicenseKeyVault`. docs/licensing.md.

### Changed
- Stable requirements, no dev stability: omnisense/omnibase `^5.1`, omnibridge/symfony `^1.1`, omnibase/admin and
  omnibase/marketplace `^1.0`, omnisense/omnistate and omnistate/annuaire-entreprises `^1.0`; `dev-main` is aliased
  `1.x-dev`. git/git-bundle stays `1.x-dev` until it is tagged.
- `forge.hub.color` is checked when the configuration is read: `#rrggbb` or nothing. Anything else is a
  configuration error at boot, where it was served as is in hub.json (and omnisense/omnihub's `HubManifest`, which
  the manifest moves onto, throws on it). `ForgeConfigurationTest`.
- **Requires omnisense/omnihub** (`^1.0`): the Unity registry's package documents (`/upm/<name>`) and listing
  (`/upm/-/all`) are written by `Omnihub\Upm\Registry` from `Omnihub\Package`s that `UpmRegistry` builds with the
  versions the caller may install; the search (`/upm/-/v1/search`) by `Registry::search()` with `glitchr` as its
  publisher; the UPM tarball by `Omnihub\Tarball` (`ArtifactBuilder::tar()` delegates to it); the hub manifest
  (`/upm/-/hub.json`) by `Omnihub\HubManifest`, which `HubCatalog::manifest()` fills with its sources, checkout,
  licences and `Omnihub\Licensing` key, the default scopes from `HubManifest::scopesOf()`. Every document is byte for
  byte what it was (the snapshot test); only the catalogue stays the forge's. Not the same output only for a malformed package.json
  whose `keywords` is not a list: the package document now leaves it out (or re-indexes it).
- A licence token is bound to its registry and its package (plan item 17.2): the payload's format is 2 and carries
  `registry` (the Unity registry's address, the manifest's `licensing.registry`) and `package` (the UPM name, null
  for a game or an application). Forge Seal reads it unchanged (its JsonUtility ignores the new fields).
- `forge:license:keypair` seals the secret key in the secrets vault (`--local`: `.env.<env>.local`) instead of
  printing it, and refuses once a key is set (`forge:license:rotate` replaces it).
- The invoices of hours are issued through omnibase/marketplace's `Invoices::issueFor()` (and previewed by
  `compose()`): `Invoice\HourInvoiceComposer::source()` gives a `Model\InvoiceSource` - the buyer from
  `InvoiceComposer::buyerOf()`, the lines, the totals, the VAT of a service - and marketplace writes the seller, the
  mentions and the number. Its `seller()`, `buyer()`, `mentions()` and `creditMention()` are gone, and with them the
  settings forge read for them. A credit note's reason is passed as typed: marketplace names the invoice it
  cancels. Each line gives its period (the month): the invoice's billing period, "Période de facturation" on the PDF,
  BT-73/74 in the Factur-X; the mention "Prestations réalisées du … au …" (`@forge.invoice.mention.period`) is
  dropped. The back office's lines are the field's own template (`setTemplatePath()`), no longer rendered by the
  controller. **Requires** omnibase/marketplace a03428c, 92d9691, 2042c64 and omnibase/admin 4e689b3, updated with
  forge; an older marketplace refuses an invoice of hours with a message saying so.
- `HourInvoice`'s keys to its invoice and credit note declare `onDelete: 'RESTRICT'` again, now that omnisense/omnibridge
  (9e2b26d) describes RESTRICT as Doctrine does; the schema is the same (glitchr's migration already writes it).

### Added
- The forge invoices its hours (plan 0, chantier I): a project invoiced on the time spent (`Project::$hourlyRate`)
  takes nothing off support hours; its time is invoiced month by month to the Partner as omnibase/marketplace's
  `Invoice` - same numbering under symfony/lock, PDF, Factur-X, sending through omnibill or e-mail, credit notes.
  `Entity\HourInvoice` (draft, issued, cancelled), `TimeEntry::$hourInvoice` / `$invoiceLine` (the line that billed
  an hour), `Invoice\HourInvoiceComposer`, `Service\HourInvoices`, `EventListener\HourInvoiceCancelListener` (any
  credit note releases the hours), `Security\Voter\HourInvoiceVoter` (the Partner reads the PDF on
  `/factures/{number}`), the back office's `HourInvoiceCrudController`, the Partner's `/compte/factures`
  (`forge_account_invoices`), configuration `forge.invoicing.vat_rate` / `vat_exemption`. **A site adds by a
  migration** the table `forge_hour_invoice`, the columns `forge_time_entry.hourInvoice_id`, `invoiceLine` and the
  project's `hourlyRate`. docs/invoices.md; tests/Service/HourInvoicesTest, HourLedgerTest.

### Fixed
- The board's `--forge-board-*` custom properties set on an ancestor of `.forge-board` reach it, as docs/board.md says:
  `public/css/board.css` declared every default on `.forge-board` itself, so a wrapper's value was overridden. The
  defaults are fallbacks of private `--_forge-board-*` tokens now (graphjs's pattern); `--forge-board-ink` falls back
  to `currentColor` (an `inherit` fallback is not a colour).

### Added
- The pipelines are read from the host of a project's repository: `Service\PipelineSync` records a project's latest
  runs - a run seen before brought up to date, its jobs no longer run taken out -, `forge:pipelines:sync
  [projects...] [--limit=10]` runs it (cron), and a button on the back office's pipelines page
  (`forge_admin_pipelines_sync`, POST). The hosts are sources, `Pipeline\PipelineSourceInterface` (tag
  `forge.pipeline_source`, value objects `Model\PipelineRun` / `PipelineRunJob`); `Pipeline\GithubActionsSource` reads
  GitHub Actions through its REST API, the stages and edges from the workflow file's `needs` (`GITHUB_TOKEN` for a
  private repository; `symfony/http-client` and `symfony/yaml` suggested, the source idle without the client).
  `Pipeline::$name` (column `forge_pipeline.name`, nullable: **a site with the pipelines' tables adds it by a
  migration**), shown on the lists. `ProjectRepository::findWithRepositoryUrl()`, `Pipeline::keep()`, `Stage::keep()`.
  docs/pipelines.md, docs/reference.md; tests/Pipeline/GithubActionsSourceTest (MockHttpClient), tests/Service/PipelineSyncTest.

### Changed
- `QuoteCrudController` numbers a new quote in `persistRecord()`, omnibase/admin's write hook on the persistence the site
  chose, in place of the former `persistEntity(EntityManagerInterface)` (deprecated since the admin's step B1a); its
  repository is given by `setQuotes()`. Needs omnibase/admin from its step B1a (`persistRecord()`, 2026-11-08).

### Changed
- Accepts omnisense/omnibase 5.0 (`3.0.*-dev || 4.0.*-dev || 5.0.*-dev`; plan "omnibase agnostic", step S): the bundle runs
  unchanged on it, `Base\Bundle\AbstractBaseBundle` and the other former names it uses being aliases of
  omnibridge/symfony's classes there. The sites on 4.x resolve as before.
- `Software::__toLink()` link through `Base\Contract\Link::to()` (the Urls contract; the same address under
  Symfony, in the core 3.x and 4.x) instead of BaseTrait's `getRouter()->generate()`, which the
  core deprecates since BaseTrait names contracts only (omnisense/omnibase 4.x, step F2f).

### Fixed
- README: the package's name, omnibase/forge (the title was base-bundle-forge), omnisense/omnibase
  and omnibase/marketplace on GitHub in place of base-bundle and base-bundle-market on the
  abandoned gitlab.glitchr.dev, git/git-bundle's home, omnisense/omnistate (not glitchr/omnistate),
  and the core's requirement as composer.json states it (3.0 or 4.0).
