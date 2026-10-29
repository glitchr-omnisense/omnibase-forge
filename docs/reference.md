---
title: "Reference"
order: 45
---

# Reference

## Routes

| Name | Path | Methods | Access |
|---|---|---|---|
| `forge_downloads` | `/telechargements` | any | everyone |
| `forge_software` | `/telechargements/{slug}` | any | everyone |
| `forge_download` | `/telechargements/{slug}/{version}` | any | checks the licence, redirects to a signed address |
| `forge_download_file` | `/telechargements/fichier/{id}/{filename}` | any | the signed address |
| `forge_play` | `/play/{slug}/{version}/{path}` | GET, HEAD | a WebGL build's files, with the headers Unity's compressed builds need ([Games](games.md)) |
| `forge_composer_packages` | `/composer/packages.json` | GET | everyone; the licensed packages for HTTP Basic (e-mail, licence key) |
| `forge_upm_search`, `forge_upm_all` | `/upm/-/v1/search`, `/upm/-/all` | GET | the Unity registry ([The Unity registry](upm.md)) |
| `forge_upm_package` | `/upm/{name}` | GET, HEAD | the same |
| `forge_upm_tarball` | `/upm/{name}/-/{filename}` | GET, HEAD | the same; licensed packages with the licence key as token |
| `forge_hub_manifest` | `/upm/-/hub.json` | GET | everyone ([The hub](hub.md)) |
| `forge_hub_catalog` | `/upm/-/hub/catalog.json` | GET | everyone |
| `forge_hub_licenses` | `/upm/-/hub/licenses` | GET | a licence key: its licences and its seat's machines (a hub client's Licences page) |
| `forge_hub_device`, `forge_hub_token` | `/upm/-/hub/device`, `/upm/-/hub/token` | POST | the device flow |
| `forge_hub_link` | `/hub/link` | GET, POST | `ROLE_USER`: the code a device shows |
| `forge_api_license_activate`, `forge_api_license_release` | `/api/licenses/activate`, `/api/licenses/release` | POST | a licence key ([Licensing](licensing.md)) |
| `forge_api_license_public_key` | `/api/licenses/public-key` | GET | everyone: the key that checks activation tokens |
| `forge_account_licenses` | `/compte/licences` | any | `ROLE_USER` |
| `forge_license_rekey` | `/compte/licences/{id}/cle` | POST | `ROLE_USER`: a new key, shown once |
| `forge_license_seat_give` | `/compte/licences/{id}/postes` | POST | `ROLE_USER` |
| `forge_license_seat_take_back` | `/compte/licences/{id}/postes/{seat}/retirer` | POST | `ROLE_USER` |
| `forge_license_machine_release` | `/compte/licences/{id}/machines/{activation}/liberer` | POST | `ROLE_USER` |
| `forge_project_board` | `/projets/{id}/tableau` | GET | `ROLE_USER` ([The project board](board.md)) |
| `forge_board_card` | `/projets/cartes/{id}` | PATCH | `ROLE_USER`: a card moved |
| `forge_review` | `/avis/{token}` | GET, POST | the review's token: a client reviews a project |
| `forge_shop` | `/support` | any | everyone |
| `forge_quote_request` | `/support/devis` | GET, POST | everyone ([Quotes](quotes.md)) |
| `forge_quote` | `/devis/{token}` | any | the quote's token |
| `forge_quote_accept` | `/devis/{token}/accepter` | POST | `ROLE_USER` |
| `forge_admin_release_build` | `/admin/forge/release/{id}/build` | any | `ROLE_ADMIN`: build a release's archive |
| `forge_admin_release_builds` | `/admin/forge/release/{id}/builds` | GET, POST | `ROLE_ADMIN`: a release's platform builds |
| `forge_admin_quote_send` | `/admin/forge/quote/{id}/send` | any | `ROLE_ADMIN` |
| `forge_admin_project_review` | `/admin/forge/project/{id}/review` | any | `ROLE_ADMIN`: ask the client for a review |
| `forge_admin_order_paid` | `/admin/forge/order/{reference}/paid` | POST | `ROLE_ADMIN`: a bank transfer received |
| `forge_admin_order_refund` | `/admin/forge/order/{reference}/refund` | GET, POST | `ROLE_ADMIN`: hours refunded |
| `forge_admin_pipelines`, `forge_admin_pipeline` | `/admin/forge/pipelines`, `/admin/forge/pipelines/{id}` | GET | `ROLE_ADMIN` ([Pipelines](pipelines.md)) |

## Commands

| Command | Arguments and options | Does |
|---|---|---|
| `forge:release:sync` | `[slugs...]`, `--no-fetch`, `--no-build` | fetches the repositories, turns new version tags into releases and builds their zip |
| `forge:artifact:attach` | `slug version platform file`, `--publish`, `--changelog` | attaches a platform build (webgl, windows, macos, linux, android) to a release, made if missing |
| `forge:license:expire` | | marks the licences past their expiry as expired |
| `forge:license:keypair` | | makes the key pair that signs the licence activation tokens |
| `forge:hours:low-balance` | `--dry-run` | e-mails the clients whose support hours run low |

## Twig

| Name | Kind | Returns |
|---|---|---|
| `forge_pipelines(project, limit = 5)` | function | a project's latest pipelines |
| `forge_pipeline_graph(pipeline)` | function | a run's nodes and edges, for `Graph.setData()` |
| `forge_pipeline_sources(pipeline)` | function | each node and the nodes it comes from |
| `forge_hours` | filter | minutes as hours and minutes |
| `forge_money(currency = 'EUR', locale = null)` | filter | cents as an amount |
| `forge_price(currency = 'EUR', locale = null)` | filter | cents as a price, marked safe |

## Listeners

| Listener | On |
|---|---|
| `OrderPaidSubscriber` | omnibase/marketplace's `OrderPaidEvent`: delivers hour packs, licences, quotes ([Installation](installation.md#how-a-purchase-is-delivered)) |
| `TimeEntryLedgerSubscriber` | Doctrine's `onFlush`: logged time debits the hours ledger |
| `LicenseSeatListener` | a seat saved from the back office: its holder is told once, as from the owner's account |
| `LicenseApiErrorSubscriber` | the licence API's errors answered in JSON with their own status (422 for a request it cannot read), never the site's HTML error page |

## Enums

| Enum | Cases |
|---|---|
| `SoftwareKind` | `package`, `game`, `app` |
| `SoftwareStatus` | `live`, `dev`, `draft` |
| `Pricing` | `free`, `licensed` |
| `Platform` | `webgl`, `windows`, `macos`, `linux`, `android` |
| `LicenseStatus` | `active`, `expired`, `revoked` |
| `ProjectStatus` | `open`, `paused`, `done` |
| `CardColumn` | `backlog`, `progress`, `review`, `done` |
| `PipelineStatus` | `pending`, `running`, `success`, `warning`, `failed`, `canceled`, `skipped`, `manual` |
| `QuoteStatus` | `requested`, `draft`, `sent`, `accepted`, `paid`, `declined` |
| `CreditReason` | `purchase`, `time`, `adjustment`, `refund` |

## Back office screens

Projects, cards, time entries, pipelines, project reviews, software,
releases, downloads, licences, licence offers, seats, activations, hour packs,
hour credits, hour refunds and quotes, each a CRUD of omnibase/admin.
