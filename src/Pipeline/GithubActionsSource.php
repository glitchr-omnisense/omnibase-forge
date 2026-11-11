<?php

namespace Base\Forge\Pipeline;

use Base\Forge\Entity\Project;
use Base\Forge\Enum\PipelineStatus;
use Base\Forge\Model\PipelineRun;
use Base\Forge\Model\PipelineRunJob;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The runs of GitHub Actions, for a project whose repository is on github.com - read through
 * GitHub's REST API: the latest workflow runs, the jobs of each, and the workflow file at the
 * run's commit, whose `needs` give the stages (a job one rank past the farthest job it needs) and
 * the edges of the graph. Without the file (no symfony/yaml, a file GitHub does not give), a run
 * is one stage, named after its workflow.
 *
 * A public repository answers without a token (60 calls an hour); a private one, and more calls,
 * need GITHUB_TOKEN (a token that reads Actions). Each run costs two calls, the workflow file of a
 * commit read once.
 *
 * Needs symfony/http-client: without it the source supports nothing.
 */
final class GithubActionsSource implements PipelineSourceInterface
{
    public const SOURCE = 'github';
    private const API = 'https://api.github.com';

    /** @var array<string, array<string, array{display: string, needs: list<string>}>|null> workflow file at a commit -> its jobs */
    private array $workflows = [];

    public function __construct(
        private readonly ?HttpClientInterface $http = null,
        #[Autowire(env: 'default::GITHUB_TOKEN')] private readonly ?string $token = null,
        private readonly string $api = self::API,
    ) {
    }

    public function supports(Project $project): bool
    {
        return null !== $this->http && null !== self::repository($project->getRepositoryUrl());
    }

    public function runs(Project $project, int $limit = 10): array
    {
        $repository = self::repository($project->getRepositoryUrl());
        if (null === $this->http || null === $repository) {
            return [];
        }

        $answer = $this->get(sprintf('/repos/%s/actions/runs', $repository), ['per_page' => max(1, min(100, $limit)), 'exclude_pull_requests' => 'true']);
        $runs = [];
        foreach (\array_slice($answer['workflow_runs'] ?? [], 0, $limit) as $run) {
            $runs[] = $this->run($repository, $run);
        }

        return $runs;
    }

    /**
     * owner/name of a github.com repository address - https://github.com/o/n(.git), git@github.com:o/n.git,
     * ssh://git@github.com/o/n.git -, or null for another host.
     */
    public static function repository(?string $url): ?string
    {
        if (null === $url || '' === trim($url)) {
            return null;
        }
        if (!preg_match('~^(?:https?://(?:[^@/]+@)?|ssh://git@|git@)(?:www\.)?github\.com[:/]([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$~', trim($url), $m)) {
            return null;
        }

        return $m[1].'/'.$m[2];
    }

    /** A status of GitHub's (status, then conclusion once completed) as the forge says it. */
    public static function status(?string $status, ?string $conclusion): PipelineStatus
    {
        if ('completed' !== $status) {
            return match ($status) {
                'in_progress' => PipelineStatus::RUNNING,
                'waiting', 'action_required' => PipelineStatus::MANUAL,
                default => PipelineStatus::PENDING, // queued, requested, pending
            };
        }

        return match ($conclusion) {
            'success' => PipelineStatus::SUCCESS,
            'failure', 'timed_out', 'startup_failure' => PipelineStatus::FAILED,
            'cancelled', 'stale' => PipelineStatus::CANCELED,
            'skipped' => PipelineStatus::SKIPPED,
            'neutral' => PipelineStatus::WARNING,
            'action_required' => PipelineStatus::MANUAL,
            default => PipelineStatus::PENDING,
        };
    }

    /** @param array<string, mixed> $run one of GitHub's workflow_runs */
    private function run(string $repository, array $run): PipelineRun
    {
        $name = (string) ($run['name'] ?? 'workflow');
        $sha = isset($run['head_sha']) ? (string) $run['head_sha'] : null;
        $workflow = isset($run['path']) && $sha ? $this->workflow($repository, (string) $run['path'], $sha) : null;

        $answer = $this->get(sprintf('/repos/%s/actions/runs/%s/jobs', $repository, $run['id']), ['per_page' => 100]);
        $jobs = $answer['jobs'] ?? [];

        // Each job of the run to the job of the file it comes from (a matrix: "test (8.4)"; a called workflow: "build / lint").
        $of = [];
        foreach ($jobs as $index => $job) {
            $of[$index] = null === $workflow ? null : self::match((string) ($job['name'] ?? ''), $workflow);
        }
        $depth = null === $workflow ? [] : self::depths($workflow);
        $names = [];
        foreach ($jobs as $index => $job) {
            if (null !== $of[$index]) {
                $names[$of[$index]][] = self::cut((string) $job['name'], 128);
            }
        }

        // The stages: by rank, named after the jobs of the file they hold.
        $stages = [];
        foreach ($jobs as $index => $job) {
            $id = $of[$index];
            $stages[null === $id ? 0 : $depth[$id]][] = $id;
        }
        ksort($stages);
        $stageName = [];
        foreach ($stages as $rank => $ids) {
            $ids = array_values(array_unique(array_filter($ids, 'is_string')));
            $stageName[$rank] = self::cut($ids ? implode(', ', $ids) : $name, 64);
        }

        $list = [];
        foreach ($jobs as $index => $job) {
            $id = $of[$index];
            $needs = [];
            foreach (null === $id ? [] : $workflow[$id]['needs'] as $need) {
                array_push($needs, ...($names[$need] ?? []));
            }
            $list[] = new PipelineRunJob(
                name: self::cut((string) ($job['name'] ?? 'job'), 128),
                stage: $stageName[null === $id ? 0 : $depth[$id]],
                status: self::status($job['status'] ?? null, $job['conclusion'] ?? null),
                needs: $needs,
                url: isset($job['html_url']) ? (string) $job['html_url'] : null,
                startedAt: self::date($job['started_at'] ?? null),
                finishedAt: self::date($job['completed_at'] ?? null),
            );
        }
        // Jobs in the order of their stages, so the stages are made in order.
        usort($list, fn (PipelineRunJob $a, PipelineRunJob $b) => array_search($a->stage, $stageName, true) <=> array_search($b->stage, $stageName, true));

        $status = self::status($run['status'] ?? null, $run['conclusion'] ?? null);

        return new PipelineRun(
            source: self::SOURCE,
            externalId: (string) $run['id'],
            name: $name,
            ref: isset($run['head_branch']) ? (string) $run['head_branch'] : null,
            commitSha: $sha,
            url: isset($run['html_url']) ? (string) $run['html_url'] : null,
            createdAt: self::date($run['run_started_at'] ?? $run['created_at'] ?? null),
            finishedAt: 'completed' === ($run['status'] ?? null) ? self::date($run['updated_at'] ?? null) : null,
            // The run's own word wins only where its jobs cannot say it: canceled, or a run without jobs.
            status: PipelineStatus::CANCELED === $status || !$jobs ? $status : null,
            jobs: $list,
        );
    }

    /**
     * The jobs of a workflow file at a commit: id -> its name as GitHub shows it, and the ids it needs.
     *
     * @return array<string, array{display: string, needs: list<string>}>|null
     */
    private function workflow(string $repository, string $path, string $sha): ?array
    {
        $key = $repository.'@'.$sha.':'.$path;
        if (\array_key_exists($key, $this->workflows)) {
            return $this->workflows[$key];
        }
        if (!class_exists(Yaml::class)) {
            return $this->workflows[$key] = null;
        }

        try {
            $response = $this->http->request('GET', $this->api.sprintf('/repos/%s/contents/%s', $repository, implode('/', array_map('rawurlencode', explode('/', $path)))), [
                'query' => ['ref' => $sha],
                'headers' => $this->headers('application/vnd.github.raw+json'),
                'timeout' => 15,
            ]);
            $yaml = 200 === $response->getStatusCode() ? Yaml::parse($response->getContent()) : null;
        } catch (\Throwable) {
            $yaml = null;
        }
        if (!\is_array($yaml) || !\is_array($yaml['jobs'] ?? null)) {
            return $this->workflows[$key] = null;
        }

        $jobs = [];
        foreach ($yaml['jobs'] as $id => $job) {
            $id = (string) $id;
            $display = \is_array($job) && \is_string($job['name'] ?? null) && !str_contains($job['name'], '${{') ? $job['name'] : $id;
            $needs = \is_array($job) ? ($job['needs'] ?? []) : [];
            $jobs[$id] = ['display' => $display, 'needs' => array_values(array_filter(array_map('strval', (array) $needs), fn (string $need) => '' !== $need))];
        }
        foreach ($jobs as $id => $job) {
            $jobs[$id]['needs'] = array_values(array_filter($job['needs'], fn (string $need) => isset($jobs[$need]) && $need !== $id));
        }

        return $this->workflows[$key] = $jobs;
    }

    /** The job of the file a job of the run comes from, or null. */
    private static function match(string $name, array $workflow): ?string
    {
        foreach ([fn (string $shown) => $name === $shown, fn (string $shown) => str_starts_with($name, $shown.' ('), fn (string $shown) => str_starts_with($name, $shown.' / ')] as $test) {
            foreach ($workflow as $id => $job) {
                if ($test($job['display']) || $test((string) $id)) {
                    return (string) $id;
                }
            }
        }

        return null;
    }

    /** @return array<string, int> each job of the file, one rank past the farthest job it needs */
    private static function depths(array $workflow): array
    {
        $depth = [];
        $visit = function (string $id, array $path) use (&$visit, &$depth, $workflow): int {
            if (isset($depth[$id])) {
                return $depth[$id];
            }
            if (isset($path[$id])) {
                return 0; // a cycle GitHub would refuse: broken here
            }
            $path[$id] = true;
            $d = 0;
            foreach ($workflow[$id]['needs'] as $need) {
                $d = max($d, $visit($need, $path) + 1);
            }

            return $depth[$id] = $d;
        };
        foreach (array_keys($workflow) as $id) {
            $visit((string) $id, []);
        }

        return $depth;
    }

    /** @return array<string, mixed> */
    private function get(string $path, array $query): array
    {
        $response = $this->http->request('GET', $this->api.$path, ['query' => $query, 'headers' => $this->headers(), 'timeout' => 15]);
        $code = $response->getStatusCode();
        if (200 !== $code) {
            throw new \RuntimeException(sprintf('GitHub answered %d for %s%s.', $code, $path, \in_array($code, [401, 403, 404], true) && !$this->token ? ' (a private repository, or the hourly limit: GITHUB_TOKEN)' : ''));
        }

        return $response->toArray();
    }

    /** @return array<string, string> */
    private function headers(string $accept = 'application/vnd.github+json'): array
    {
        return array_filter([
            'Accept' => $accept,
            'X-GitHub-Api-Version' => '2022-11-28',
            'Authorization' => $this->token ? 'Bearer '.$this->token : null,
        ]);
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private static function cut(string $text, int $length): string
    {
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1).'…' : $text;
    }
}
