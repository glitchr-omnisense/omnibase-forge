# Changelog

## [Unreleased]

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
