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

On this checkout, in the harness:

```text
OK (54 tests, 513 assertions)
```

## What the tests cover

| Folder | Tests |
|---|---|
| `Controller` | the board's controller, the CRUDs' required fields, a game's pages, the hub, the pipelines' screens, the Unity registry |
| `Entity` | licences, projects, quotes |
| `Enum` | `PipelineStatusTest` |
| `Model` | `CardMoveTest`: a card's move as the board sends it |
| `Pipeline` | `GithubActionsSourceTest`: GitHub Actions through a `MockHttpClient` - the repository's address, the statuses, a run's stages and edges from its workflow file's `needs`, a run without its file, a host that refuses |
| `Service` | the board, the hours ledger, licence keys, the pipeline graph, a run recorded then brought up to date (`PipelineSyncTest`), the Unity tarballs |
