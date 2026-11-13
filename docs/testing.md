---
title: "Testing"
order: 60
---

# Testing

## Running the suite

```bash
vendor/bin/phpunit                                   # standalone
vendor/bin/phpunit -c vendor/omnibase/forge          # from a host
```

In omnisense/omnibase-harness, mounting the checkout in place of the
installed package:

```bash
docker run --rm --network none \
    -v "$PWD":/srv/demo/vendor/omnibase/forge:ro \
    omnisense/omnibase-harness:latest test forge
```

The harness image installs omnisense/omnitrade 1.0: `HubPurchaseTest` needs 1.1 and omnitrade/stripe 1.1, so mount
their checkouts over `/srv/demo/vendor/omnisense/omnitrade` and `/srv/demo/vendor/omnitrade/stripe` (an override of
`compose.checkouts.yml`, as for omnisense/omnihub) until the image is rebuilt; without them it is skipped.

On this checkout, in the harness:

```text
OK (86 tests, 893 assertions)
```

## What the tests cover

| Folder | Tests |
|---|---|
| `Controller` | the board's controller, the CRUDs' required fields, a game's pages, the hub, the one-click purchase from the hub clients (`HubPurchaseTest`: a card saved, the price confirmed, the bank's check, Stripe's signed webhook, the licence key handed over and its v2 token verified; Stripe answering through a recorded client), the pipelines' screens, the Unity registry |
| `DependencyInjection` | `ForgeConfigurationTest`: the hub's colour checked at boot |
| `Entity` | licences, projects, quotes |
| `Enum` | `PipelineStatusTest` |
| `Model` | `CardMoveTest`: a card's move as the board sends it |
| `Pipeline` | `GithubActionsSourceTest`: GitHub Actions through a `MockHttpClient` - the repository's address, the statuses, a run's stages and edges from its workflow file's `needs`, a run without its file, a host that refuses |
| `Service` | the board, the hours ledger, licence keys, the pipeline graph, a run recorded then brought up to date (`PipelineSyncTest`), the Unity tarballs |
