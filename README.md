# omnibase/forge

A software forge for [omnisense/omnibase](https://github.com/glitchr-omnisense/omnibase) applications, sold through [omnibase/marketplace](https://github.com/glitchr-omnisense/omnibase-marketplace). It covers what a studio sells besides its time on a site:

- **Software and releases.** Each git tag of a repository becomes a release. [git/git-bundle](https://github.com/glitchr-studio/git-bundle) reads the tree in-process and the release is zipped from it.
- **Downloads.** Free software downloads for anyone. Licensed software downloads against a licence bought as a market product (`LicenseOffer`). Files are served through signed, short-lived URLs and nginx `X-Accel-Redirect`.
- **A private Composer repository.** `/composer/packages.json` lists the free packages for anyone, and the licensed ones for the holder of a valid licence. Authentication is HTTP Basic with the e-mail and the licence key.
- **A Unity registry.** `/upm` speaks the npm registry protocol Unity's Package Manager reads from a scoped registry: free Unity packages for anyone, licensed ones with the licence key as the registry's token - see [docs/upm.md](docs/upm.md).
- **The hub.** A manifest (`/upm/-/hub.json`), a catalogue with the paid packages' offers and the games, and a device flow that links a Unity editor to its user's licences once bought - see [docs/hub.md](docs/hub.md).
- **Games and applications.** Builds per platform (WebGL, Windows, macOS, Linux, Android) attached to each release, a WebGL build played in the game's page with the headers Unity's compressed builds need, screenshots and a trailer - see [docs/games.md](docs/games.md).
- **Client projects.**
  - A board the client and the studio both move cards on: its page, its script and its stylesheet are the bundle's - see [docs/board.md](docs/board.md).
  - The time the studio logs, and the history of the project's repository.
  - Its pipelines: what ran on each commit, stage by stage, drawn as a graph in the back office and on the project's page - see [docs/pipelines.md](docs/pipelines.md).
- **Support hours.**
  - Prepaid packs (`HourPack`) credit a ledger.
  - Logged time debits it.
  - The balance is always the sum of the ledger's lines.
- **Quotes (special offers).**
  1. A client asks for a quote from the site.
  2. The studio prices it in the back office and sends it.
  3. The client accepts it and pays it like any order.
  4. Its hours are credited.

  What every quote is (its client, status, discount, validity, numbering) is omnibase/marketplace's `AbstractQuote`; the forge keeps the hours and the `HourPack` - see [docs/quotes.md](docs/quotes.md).

## Requirements

- PHP 8.2+, `ext-zip`
- `omnisense/omnibase` 3.0 or 4.0, `omnibase/admin`, `omnibase/marketplace`
- `git/git-bundle` 1.0, with the **php-git2** extension ([RogerGee/php-git2](https://github.com/RogerGee/php-git2))
- `omnisense/omnistate` with `omnistate/annuaire-entreprises`: they turn on omnibase/marketplace's company register, which checks the SIRETs typed in quote requests against the State's free register

## Installation

```bash
composer require omnibase/forge:dev-main
```

```php
// config/bundles.php
Omnistate\Bridge\Symfony\OmnistateBundle::class => ['all' => true],
Base\Forge\ForgeBundle::class => ['all' => true],
```

```yaml
# config/routes.yaml
forge_controller:
    resource: "@ForgeBundle/src/Controller"
    type: attribute
    prefix: /
```

```yaml
# config/packages/forge.yaml: every key is optional
forge:
    storage_dir: '%kernel.project_dir%/var/storage/forge'          # release archives, never under public/
    repositories_dir: '%kernel.project_dir%/var/storage/repositories'
    accel_prefix: '/_protected/forge/'   # nginx internal location; empty: PHP streams the file
    download_ttl: 600                    # seconds a signed link stays valid
    support_store: support               # market store selling hour packs and quotes
    software_store: logiciels            # market store selling licence offers
    hourly_rate: 6000                    # default rate of a quote line, in cents
    low_balance_minutes: 120             # below this, forge:hours:low-balance reminds the client
    contact_email: '%env(MAILER_CONTACT)%'   # where quote requests are mailed
```

```yaml
# config/packages/git.yaml: open each client their own repositories
git:
    access_role: ROLE_USER
    repository_attribute: GIT_VIEW       # Base\Forge\Security\Voter\GitRepositoryVoter
```

Every project and piece of software with a `repository` shows up in git-bundle's viewer on its own, through `Base\Forge\Git\ForgeRepositoryProvider`. The bundle also needs an nginx `internal` location for the archives:

```nginx
location /_protected/forge/ {
    internal;
    alias /srv/app/var/storage/forge/;
}
```

## How a purchase is delivered

`Base\Marketplace\Service\Checkout::confirm()` dispatches `OrderPaidEvent`, and `Base\Forge\EventSubscriber\OrderPaidSubscriber` delivers the order:

| Product | Delivered |
|---|---|
| `HourPack` | minutes × quantity credited to the buyer's ledger |
| `LicenseOffer` | one `License` per unit (seats, duration and updates taken from the offer) |
| a quote's own `HourPack` | its hours credited, and the quote marked paid |

Delivery is idempotent per order reference. Bank transfers (the `manual` gateway) stay pending until someone in the admin posts to `forge_admin_order_paid`, which confirms the order.

Licence keys (`glk_…`) are generated by their owner from `/compte/licences` and shown once. Only their SHA-256 is stored.

## Commands

| Command | What it does | Suggested cron |
|---|---|---|
| `forge:release:sync [slug…]` | fetch the repositories, turn new version tags into releases and build their zip | hourly |
| `forge:license:expire` | mark the licences past their expiry as expired | daily |
| `forge:hours:low-balance [--dry-run]` | e-mail the clients whose support hours run low | weekly |

## Routes

| Path | Name |
|---|---|
| `/telechargements`, `/telechargements/{slug}` | `forge_downloads`, `forge_software` |
| `/telechargements/{slug}/{version}` | `forge_download` (checks the licence, redirects to a signed URL) |
| `/telechargements/fichier/{id}/{filename}` | `forge_download_file` (signed) |
| `/support`, `/support/devis` | `forge_shop`, `forge_quote_request` |
| `/devis/{token}`, `/devis/{token}/accepter` | `forge_quote`, `forge_quote_accept` |
| `/compte/licences` | `forge_account_licenses` |
| `/composer/packages.json` | `forge_composer_packages` |
| `/admin/forge/…` | release build, quote send, order paid |

The templates extend the host's `layout1.html.twig`, as omnibase/marketplace's do. They use `forge-*` classes for the host to style, and can be overridden in `templates/bundles/ForgeBundle/`.

## Tests

```bash
vendor/bin/phpunit   # standalone, or from a host: vendor/bin/phpunit -c vendor/omnibase/forge
```

## Licence

MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
