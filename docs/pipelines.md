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

The bundle has no listener for a CI's webhooks yet: what records the runs (a webhook's controller, a command the CI calls) is the application's for now.

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

`forge_pipeline`, `forge_pipeline_stage`, `forge_pipeline_job`: an application that updates the bundle writes their migration (`doctrine:migrations:diff`).
