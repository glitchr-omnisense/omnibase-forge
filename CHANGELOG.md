# Changelog

## [Unreleased]

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
