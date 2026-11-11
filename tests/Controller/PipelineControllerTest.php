<?php

namespace Tests\Base\Forge\Controller;

use Base\Forge\Entity\Pipeline;
use Base\Forge\Entity\Project;
use Base\Forge\Enum\PipelineStatus;
use Tests\Base\Forge\ForgeWebTestCase;

/**
 * The pipelines as they are seen, in a host application: in the back office - the list, one run
 * as its graph - and under its board on a project's page.
 */
class PipelineControllerTest extends ForgeWebTestCase
{
    public function testTheBackOfficeDrawsARunAsItsGraph(): void
    {
        [$client, $project, $pipeline] = $this->pipeline();
        $admin = $this->user('admin', ['ROLE_ADMIN', 'ROLE_SUPERADMIN']);
        $this->em->flush();
        $this->signIn($admin);

        $page = $this->request('GET', '/admin/forge/pipelines/'.$pipeline->getId());
        self::assertSame(200, $page->getStatusCode(), substr(strip_tags((string) $page->getContent()), 0, 1500));
        $dom = $this->xpath($page);
        $graph = '//*[@id="forge-pipeline-'.$pipeline->getId().'"]';
        self::assertSame('pipeline', $dom->evaluate('string('.$graph.'/@data-graph-preset)'), 'graphjs\'s preset');
        self::assertSame(3, $dom->query($graph.'//*[@data-graph-node]')->length, 'a node per job');
        self::assertSame(['0', '1', '1'], $this->attributes($dom, $graph.'//*[@data-graph-node]', 'data-graph-rank'), 'ranked by stage');
        self::assertSame(['success', 'failed', 'running'], $this->attributes($dom, $graph.'//*[@data-graph-node]', 'data-graph-state'));
        $first = $dom->evaluate('string('.$graph.'//*[@data-graph-node][1]/@data-graph-node)');
        self::assertSame([$first, $first], $this->attributes($dom, $graph.'//*[@data-graph-from]', 'data-graph-from'), 'the tests wait for the install');
        self::assertSame('https://ci.example.org/jobs/7', $dom->evaluate('string('.$graph.'//a[@data-graph-node]/@href)'), 'a job with a log is a link to it');
        self::assertSame(3, $dom->query('//table[contains(@class, "forge-pipeline-jobs")]/tbody/tr')->length, 'and its jobs in a table');

        $list = $this->request('GET', '/admin/forge/pipelines');
        self::assertSame(200, $list->getStatusCode());
        self::assertSame(1, $this->xpath($list)->query($graph)->length, 'the latest runs, each with its graph');

        // The runs read again from the hosts, on demand: a form with its token; none of these projects is on a host a source reads.
        $token = $this->xpath($list)->evaluate('string(//form[contains(@action, "/admin/forge/pipelines/sync")]//input[@name="_token"]/@value)');
        self::assertNotSame('', $token, 'the button to read them again');
        self::assertSame(403, $this->request('POST', '/admin/forge/pipelines/sync', [], null, ['_token' => 'wrong'])->getStatusCode());
        $synced = $this->request('POST', '/admin/forge/pipelines/sync', [], null, ['_token' => $token]);
        self::assertSame(302, $synced->getStatusCode());
        self::assertStringEndsWith('/admin/forge/pipelines', (string) $synced->headers->get('Location'));

        // Not the back office's: a client is turned away.
        $this->signIn($client);
        self::assertContains($this->request('GET', '/admin/forge/pipelines')->getStatusCode(), [302, 403, 404]);
    }

    public function testAProjectsPageShowsItsRunsUnderItsBoard(): void
    {
        [$client, $project, $pipeline] = $this->pipeline();
        $this->signIn($client);

        $page = $this->request('GET', '/projets/'.$project->getId().'/tableau');
        self::assertSame(200, $page->getStatusCode(), substr(strip_tags((string) $page->getContent()), 0, 1500));
        $dom = $this->xpath($page);
        self::assertSame(1, $dom->query('//*[@data-forge-board]')->length);
        self::assertSame(3, $dom->query('//*[@id="forge-pipeline-'.$pipeline->getId().'"]//*[@data-graph-node]')->length);
        self::assertSame(1, $dom->query('//script[contains(@src, "bundles/admin/js/graph.js")]')->length, 'the library, loaded once');
        self::assertStringContainsString('is-failed', $dom->evaluate('string(//article[contains(@class, "forge-pipeline")]/@class)'), 'a run in the colour of its status');
    }

    /** @return array{0: \Base\Entity\User, 1: Project, 2: Pipeline} a client, their project, a run of it: installed, a test failed, another runs */
    private function pipeline(): array
    {
        $client = $this->user('client');
        $this->em->flush();

        $project = new Project($client, 'Site '.bin2hex(random_bytes(3)));
        $project->setClient($client);
        $this->em->persist($project);

        $pipeline = (new Pipeline($project, 'main', str_repeat('c', 40)))->setSource('test', bin2hex(random_bytes(6)));
        $pipeline->stage('install')->job('composer', PipelineStatus::SUCCESS);
        $pipeline->stage('test')->job('phpunit', PipelineStatus::FAILED)->setUrl('https://ci.example.org/jobs/7');
        $pipeline->stage('test')->job('lint', PipelineStatus::RUNNING);
        $this->em->persist($pipeline);
        $this->em->flush();

        return [$client, $project, $pipeline];
    }

    /** @return list<string> */
    private function attributes(\DOMXPath $dom, string $query, string $attribute): array
    {
        $values = [];
        foreach ($dom->query($query) as $node) {
            $values[] = $node->getAttribute($attribute);
        }

        return $values;
    }
}
