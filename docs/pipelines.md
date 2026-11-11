---
title: "Pipelines"
order: 34
---

# Pipelines

A pipeline (`Base\Forge\Entity\Pipeline`) is one run of a project's checks on a commit - its tests, its build, its deployment -, wherever it ran: a forge's CI, a script. It has stages in order (`Stage`), each with its jobs (`Job`). The bundle records what happened and shows it; it runs nothing itself.

## Recording a run

```php
use Base\Forge\Entity\Pipeline;
use Base\Forge\Enum\PipelineStatus;

$pipeline = $pipelines->findOneBySource('gitlab', '4821')            // the same run, brought up to date
    ?? (new Pipeline($project, 'main', $sha))->setSource('gitlab', '4821');
$pipeline->setUrl('https://gitlab.example.org/site/-/pipelines/4821');

$pipeline->stage('install')->job('composer', PipelineStatus::SUCCESS);
$pipeline->stage('test')->job('phpunit', PipelineStatus::RUNNING)->setUrl('https://gitlab.example.org/site/-/jobs/9917');
$pipeline->stage('test')->job('lint', PipelineStatus::FAILED)->setAllowFailure(true);
$pipeline->stage('deploy')->job('production', PipelineStatus::MANUAL)->setNeeds(['phpunit']);

$entityManager->persist($pipeline);
$entityManager->flush();
```

`stage()` and `job()` find the one of that name or make it, so the same lines bring a run up to date as its jobs move on. `source` and its id name a run once (a unique pair).

A status (`Base\Forge\Enum\PipelineStatus`: pending, running, success, warning, failed, canceled, skipped, manual) is set on jobs only. A stage is what its jobs say and a pipeline what its stages say - failed as soon as one failed, running while one runs, waiting on a manual step - unless the pipeline's own status was set by hand (`setStatus()`). A job allowed to fail that failed counts as a warning.

A run may carry a name (`setName()`): what it is called where it ran, a GitHub workflow's name ("CI", "Docs").

## Reading the runs from the host

`Service\PipelineSync` reads a project's latest runs where its repository lives and records them as above: a run seen before (the same source and id) is brought up to date - its jobs moved on, the ones no longer run taken out -, a new one is added.

```sh
bin/console forge:pipelines:sync                 # every project with a repositoryUrl
bin/console forge:pipelines:sync 12 --limit=20   # one project, its 20 latest runs
```

Run it from cron every few minutes. The back office's pipelines page has a button that does the same now (`forge_admin_pipelines_sync`).

A host is read by a source, `Base\Forge\Pipeline\PipelineSourceInterface` (`supports(Project)`, `runs(Project, limit)`: value objects, `Model\PipelineRun` and `Model\PipelineRunJob`, no entity), autoconfigured with the tag `forge.pipeline_source`. One ships:

- **`GithubActionsSource`**, for a `repositoryUrl` on github.com (`https://github.com/o/n`, `git@github.com:o/n.git`, `ssh://git@github.com/o/n.git`): GitHub's REST API, the latest workflow runs, the jobs of each, and the workflow file at the run's commit, whose `needs` give the stages - a job one rank past the farthest job it needs, a matrix's jobs (`Tests (8.4)`) in their job's stage, each stage named after the jobs of the file it holds - and the edges of the graph. Without the file (no `symfony/yaml`, a file GitHub does not give), a run is one stage named after its workflow. GitHub's statuses: queued and pending → pending, in progress → running, waiting → manual; then success, failure / timed out → failed, cancelled → canceled (said by the run itself), skipped, neutral → warning. A public repository answers without a token, 60 calls an hour; a private one, and more calls, need `GITHUB_TOKEN` in the environment (a token that reads Actions). Each run costs two calls; a workflow file is read once per commit. Needs `symfony/http-client`: without it the source supports nothing, and nothing else changes.

Why a source in the bundle and not a family `omniXXX` (plan 0, rule 2: a family aggregates an API with at least two real providers): the studio's repositories are all on GitHub today (GitLab was left, gitbud has no CI). When a second host's CI is used - GitLab CI, Forgejo Actions, a CI of gitbud's - it is one more class here, and the sources move out to a family then.

A CI's webhooks are not listened to yet: the command (cron) brings the runs up to date.

## Seeing it

| | |
|---|---|
| `/admin/forge/pipelines` (`forge_admin_pipelines`) | The latest runs of every project, each as its graph |
| `/admin/forge/pipelines/{id}` (`forge_admin_pipeline`) | One run: its graph, its jobs in a table, the project's earlier runs |
| `PipelineCrudController` | The runs in the back office's lists, with a "Graphe" action |
| `@Forge/client/project/_pipelines.html.twig` | A project's latest runs, to include in a page of one's own; the board's page (`forge_project_board`) does |
| `@Forge/_pipeline.html.twig` | One run as its graph |

```twig
{% include '@Forge/client/project/_pipelines.html.twig' with {project: project, limit: 3} %}
```

## The graph

A run is drawn by [`@glitchr/graphjs`](https://github.com/glitchr-studio/graphjs), which omnibase/admin vendors and its layout loads on every page of the back office: a node per job - its stage under its name, in the colour of its status -, ranked by stage, an edge from each job it waits for (the ones `needs` names, or every job of the stage before). The template writes it in the page (`data-graph`, the `pipeline` preset), so the jobs still read, in order, without the script. Outside the back office the include loads the library itself, once per page (`assets: false` when the page already does).

`Base\Forge\Service\PipelineGraph::data($pipeline)` returns the same graph as data - `{nodes: [{id, label, sub, rank, state, href, title}], edges: [{from, to}]}` - for `Graph.setData()`, when a page follows a run that is still moving:

```js
const graph = Graph.get(document.querySelector('#forge-pipeline-12'));
graph.setState('job-31', 'success');
```

## Tables

`forge_pipeline`, `forge_pipeline_stage`, `forge_pipeline_job`: an application that updates the bundle writes their migration (`doctrine:migrations:diff`). Since 2026-11-11 `forge_pipeline` has a `name` column (nullable): a site that had the tables adds it the same way.
