<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\AppTestCase;
use Thallo\Workflow\Http\Controllers\WorkflowController;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class WorkflowApiTest extends AppTestCase
{
    use SeedsPublishedContent;

    /** Build a request whose 'user' attribute carries the acting uuid (post-auth shape). */
    private function req(string $actor, array $body = []): Request
    {
        $r = Request::create(
            '/x',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body),
        );
        $r->attributes->set('user', ['uuid' => $actor, 'roles' => [], 'scopes' => []]);
        return $r;
    }

    private function controller(): WorkflowController
    {
        return $this->container()->get(WorkflowController::class);
    }

    public function testSubmitApproveRoundTrip(): void
    {
        $entry = $this->seedBilingualPublishedEntry();

        $res = $this->controller()->submit($this->req('author000001'), $entry, 'en');
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('in_review', json_decode((string) $res->getContent(), true)['data']['state']);

        $res = $this->controller()->approve($this->req('review000001', ['note' => 'ship it']), $entry, 'en');
        self::assertSame(200, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true)['data'];
        self::assertSame('approved', $data['state']);
        self::assertSame('ship it', $data['history'][0]['note']);
    }

    public function testIllegalTransitionIs409AndSelfReview403(): void
    {
        $entry = $this->seedBilingualPublishedEntry();

        $res = $this->controller()->approve($this->req('review000001'), $entry, 'en');
        self::assertSame(409, $res->getStatusCode());
        $body = json_decode((string) $res->getContent(), true);
        self::assertSame('draft', $body['error']['details']['workflow_state']);

        $this->controller()->submit($this->req('author000001'), $entry, 'en');
        $res = $this->controller()->approve($this->req('author000001'), $entry, 'en');
        self::assertSame(403, $res->getStatusCode());
    }

    public function testRequestChangesRequiresNote(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $this->controller()->submit($this->req('author000001'), $entry, 'en');

        try {
            $this->controller()->requestChanges($this->req('review000001', []), $entry, 'en');
            self::fail('expected ValidationException (note required)');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $res = $this->controller()->requestChanges(
            $this->req('review000001', ['note' => 'tighten the intro']),
            $entry,
            'en',
        );
        self::assertSame(200, $res->getStatusCode());
        self::assertSame(
            'changes_requested',
            json_decode((string) $res->getContent(), true)['data']['state'],
        );
    }

    public function testWithdrawBySubmitter(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $this->controller()->submit($this->req('author000001'), $entry, 'en');

        // A stranger without workflow.review is forbidden.
        $res = $this->controller()->withdraw($this->req('stranger0001'), $entry, 'en');
        self::assertSame(403, $res->getStatusCode());

        $res = $this->controller()->withdraw($this->req('author000001'), $entry, 'en');
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('draft', json_decode((string) $res->getContent(), true)['data']['state']);
    }

    public function testUnknownEntryIs404(): void
    {
        $res = $this->controller()->submit($this->req('author000001'), 'missing00001', 'en');
        self::assertSame(404, $res->getStatusCode());
    }

    public function testShowReturnsDraftDefault(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $res = $this->controller()->show(Request::create('/x', 'GET'), $entry, 'en');
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('draft', json_decode((string) $res->getContent(), true)['data']['state']);
    }

    public function testQueueListsInReviewWithSummaries(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $this->controller()->submit($this->req('author000001'), $entry, 'en');

        $res = $this->controller()->queue(Request::create('/x', 'GET'));
        $data = json_decode((string) $res->getContent(), true)['data'];
        self::assertSame(1, $data['total']);
        self::assertSame($entry, $data['items'][0]['entry_uuid']);
        self::assertSame('Hello', $data['items'][0]['title']);
        self::assertSame('blog', $data['items'][0]['type_slug']);
        self::assertSame('author000001', $data['items'][0]['submitted_by']);
    }

    public function testRoutesAreRegisteredWithPermissions(): void
    {
        $route = $this->findRoute('POST', '/v1/admin/workflow/entries/{uuid}/{locale}/approve');
        self::assertNotNull($route);
        self::assertContains('lemma_permission:workflow.review', (array) ($route['middleware'] ?? []));

        $route = $this->findRoute('GET', '/v1/admin/workflow/queue');
        self::assertNotNull($route);
        self::assertContains('lemma_permission:workflow.review', (array) ($route['middleware'] ?? []));

        $route = $this->findRoute('POST', '/v1/admin/workflow/entries/{uuid}/{locale}/withdraw');
        self::assertNotNull($route);
        self::assertContains('lemma_permission:content.view', (array) ($route['middleware'] ?? []));
    }
}
