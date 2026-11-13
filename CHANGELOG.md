# Changelog

## [Unreleased]

### Added
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
