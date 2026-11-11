<?php

namespace Tests\Base\Forge\Pipeline;

use Base\Forge\Entity\Project;
use Base\Forge\Enum\PipelineStatus;
use Base\Forge\Model\PipelineRunJob;
use Base\Forge\Pipeline\GithubActionsSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** GitHub Actions read through its REST API, answered here by a MockHttpClient. */
class GithubActionsSourceTest extends TestCase
{
    private const WORKFLOW = <<<'YAML'
        name: CI
        on: [push]
        jobs:
          lint:
            runs-on: ubuntu-latest
            steps: [{run: make lint}]
          test:
            name: Tests
            needs: lint
            strategy: {matrix: {php: ['8.3', '8.4']}}
            runs-on: ubuntu-latest
            steps: [{run: make test}]
          deploy:
            needs: [test, lint]
            runs-on: ubuntu-latest
            steps: [{run: make deploy}]
        YAML;

    protected function setUp(): void
    {
        if (!class_exists(MockHttpClient::class)) {
            self::markTestSkipped('Needs symfony/http-client.');
        }
    }

    public function testItKnowsAGithubRepositoryByItsAddress(): void
    {
        foreach (['https://github.com/acme/site', 'https://github.com/acme/site.git', 'git@github.com:acme/site.git', 'ssh://git@github.com/acme/site.git', 'https://token@github.com/acme/site/'] as $url) {
            self::assertSame('acme/site', GithubActionsSource::repository($url), $url);
        }
        foreach ([null, '', 'https://gitlab.com/acme/site', 'https://github.com/acme', 'https://github.com.evil.org/acme/site', 'https://localhost:8713/acme/site.git'] as $url) {
            self::assertNull(GithubActionsSource::repository($url), (string) $url);
        }

        $project = (new Project())->setRepositoryUrl('git@github.com:acme/site.git');
        self::assertTrue((new GithubActionsSource(new MockHttpClient()))->supports($project));
        self::assertFalse((new GithubActionsSource(null))->supports($project), 'without an HTTP client, nothing');
        self::assertFalse((new GithubActionsSource(new MockHttpClient()))->supports((new Project())->setRepositoryUrl('https://gitlab.com/acme/site')));
    }

    public function testGithubsStatusesAreTheForges(): void
    {
        self::assertSame(PipelineStatus::PENDING, GithubActionsSource::status('queued', null));
        self::assertSame(PipelineStatus::RUNNING, GithubActionsSource::status('in_progress', null));
        self::assertSame(PipelineStatus::MANUAL, GithubActionsSource::status('waiting', null));
        self::assertSame(PipelineStatus::SUCCESS, GithubActionsSource::status('completed', 'success'));
        self::assertSame(PipelineStatus::FAILED, GithubActionsSource::status('completed', 'failure'));
        self::assertSame(PipelineStatus::FAILED, GithubActionsSource::status('completed', 'timed_out'));
        self::assertSame(PipelineStatus::CANCELED, GithubActionsSource::status('completed', 'cancelled'));
        self::assertSame(PipelineStatus::SKIPPED, GithubActionsSource::status('completed', 'skipped'));
        self::assertSame(PipelineStatus::WARNING, GithubActionsSource::status('completed', 'neutral'));
    }

    public function testARunIsReadWithItsStagesFromTheWorkflowsNeeds(): void
    {
        $asked = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$asked) {
            $asked[] = $method.' '.$url;
            $path = parse_url($url, \PHP_URL_PATH);

            return match (true) {
                '/repos/acme/site/actions/runs' === $path => new MockResponse(json_encode(['workflow_runs' => [
                    ['id' => 42, 'name' => 'CI', 'path' => '.github/workflows/ci.yml', 'head_branch' => 'main', 'head_sha' => str_repeat('a', 40),
                        'status' => 'in_progress', 'conclusion' => null, 'html_url' => 'https://github.com/acme/site/actions/runs/42',
                        'created_at' => '2026-11-11T08:00:00Z', 'run_started_at' => '2026-11-11T08:00:05Z', 'updated_at' => '2026-11-11T08:03:00Z'],
                    ['id' => 41, 'name' => 'CI', 'path' => '.github/workflows/ci.yml', 'head_branch' => 'main', 'head_sha' => str_repeat('a', 40),
                        'status' => 'completed', 'conclusion' => 'cancelled', 'html_url' => 'https://github.com/acme/site/actions/runs/41',
                        'created_at' => '2026-11-11T07:00:00Z', 'updated_at' => '2026-11-11T07:01:00Z'],
                ]])),
                '/repos/acme/site/contents/.github/workflows/ci.yml' === $path => new MockResponse(self::WORKFLOW),
                '/repos/acme/site/actions/runs/42/jobs' === $path => new MockResponse(json_encode(['jobs' => [
                    ['name' => 'Tests (8.3)', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/site/actions/runs/42/job/2', 'started_at' => '2026-11-11T08:01:00Z', 'completed_at' => '2026-11-11T08:02:00Z'],
                    ['name' => 'lint', 'status' => 'completed', 'conclusion' => 'success', 'html_url' => 'https://github.com/acme/site/actions/runs/42/job/1'],
                    ['name' => 'Tests (8.4)', 'status' => 'in_progress', 'conclusion' => null],
                    ['name' => 'deploy', 'status' => 'queued', 'conclusion' => null],
                ]])),
                '/repos/acme/site/actions/runs/41/jobs' === $path => new MockResponse(json_encode(['jobs' => [
                    ['name' => 'lint', 'status' => 'completed', 'conclusion' => 'success'],
                ]])),
                default => new MockResponse('', ['http_code' => 404]),
            };
        });

        $runs = (new GithubActionsSource($http, 'secret'))->runs((new Project())->setRepositoryUrl('https://github.com/acme/site'), 2);

        self::assertCount(2, $runs);
        [$run, $canceled] = $runs;
        self::assertSame(['github', '42', 'CI', 'main', str_repeat('a', 40), 'https://github.com/acme/site/actions/runs/42'], [$run->source, $run->externalId, $run->name, $run->ref, $run->commitSha, $run->url]);
        self::assertSame('2026-11-11T08:00:05+00:00', $run->createdAt->format(\DATE_ATOM), 'when it started');
        self::assertNull($run->finishedAt, 'still running');
        self::assertNull($run->status, 'what its jobs say');

        $jobs = array_map(fn (PipelineRunJob $job) => [$job->name, $job->stage, $job->status->value, $job->needs], $run->jobs);
        self::assertSame([
            ['lint', 'lint', 'success', []],
            ['Tests (8.3)', 'test', 'failed', ['lint']],
            ['Tests (8.4)', 'test', 'running', ['lint']],
            ['deploy', 'deploy', 'pending', ['Tests (8.3)', 'Tests (8.4)', 'lint']],
        ], $jobs, 'the stages by rank of the needs, a matrix\'s jobs in the stage of their job, the jobs in the order of their stages');
        self::assertSame('https://github.com/acme/site/actions/runs/42/job/2', $run->jobs[1]->url);
        self::assertSame('2026-11-11T08:02:00+00:00', $run->jobs[1]->finishedAt->format(\DATE_ATOM));

        self::assertSame(PipelineStatus::CANCELED, $canceled->status, 'a canceled run says so itself');
        self::assertSame('2026-11-11T07:01:00+00:00', $canceled->finishedAt->format(\DATE_ATOM));

        self::assertCount(1, array_filter($asked, fn (string $call) => str_contains($call, '/contents/')), 'the workflow file of a commit read once');
        self::assertContains('GET https://api.github.com/repos/acme/site/contents/.github/workflows/ci.yml?ref='.str_repeat('a', 40), $asked);
    }

    public function testWithoutItsWorkflowFileARunIsOneStage(): void
    {
        $http = new MockHttpClient(function (string $method, string $url) {
            $path = parse_url($url, \PHP_URL_PATH);

            return match ($path) {
                '/repos/acme/site/actions/runs' => new MockResponse(json_encode(['workflow_runs' => [
                    ['id' => 7, 'name' => 'Docs', 'path' => '.github/workflows/docs.yml', 'head_branch' => 'main', 'head_sha' => str_repeat('b', 40), 'status' => 'completed', 'conclusion' => 'success', 'created_at' => '2026-11-11T08:00:00Z', 'updated_at' => '2026-11-11T08:01:00Z'],
                ]])),
                '/repos/acme/site/actions/runs/7/jobs' => new MockResponse(json_encode(['jobs' => [['name' => 'build', 'status' => 'completed', 'conclusion' => 'success'], ['name' => 'publish', 'status' => 'completed', 'conclusion' => 'skipped']]])),
                default => new MockResponse('', ['http_code' => 404]),
            };
        });

        [$run] = (new GithubActionsSource($http))->runs((new Project())->setRepositoryUrl('https://github.com/acme/site'));
        self::assertSame([['build', 'Docs'], ['publish', 'Docs']], array_map(fn (PipelineRunJob $job) => [$job->name, $job->stage], $run->jobs));
        self::assertSame(PipelineStatus::SKIPPED, $run->jobs[1]->status);
    }

    public function testAHostThatRefusesIsSaid(): void
    {
        $source = new GithubActionsSource(new MockHttpClient(new MockResponse('{"message":"Not Found"}', ['http_code' => 404])));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GitHub answered 404');
        $source->runs((new Project())->setRepositoryUrl('https://github.com/acme/private'));
    }
}
