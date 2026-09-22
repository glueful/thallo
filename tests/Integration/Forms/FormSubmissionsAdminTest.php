<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Forms;

use Thallo\Core\Content\Forms\FormSubmission;
use Thallo\Core\Content\Forms\FormSubmissionRepository;
use Thallo\Core\Http\Controllers\FormSubmissionsController;
use Thallo\Core\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FormSubmissionsAdminTest extends AppTestCase
{
    private function controller(): FormSubmissionsController
    {
        return $this->container()->get(FormSubmissionsController::class);
    }

    private function repo(): FormSubmissionRepository
    {
        return $this->container()->get(FormSubmissionRepository::class);
    }

    private function seed(
        string $key,
        string $status,
        string $email,
        string $submittedAt = '2026-07-09 10:00:00',
        string $name = 'Contact',
    ): string {
        return $this->repo()->store(new FormSubmission(
            uuid: '',
            formKey: $key,
            formName: $name,
            sourceUrl: '/contact',
            fieldsSnapshot: [['key' => 'email', 'label' => 'Email', 'type' => 'email']],
            values: ['email' => $email, 'consent' => true],
            descriptorVersion: 1,
            status: $status,
            ip: '127.0.0.1',
            userAgent: 'test',
            submittedAt: $submittedAt,
        ));
    }

    /** @return array<string,mixed> */
    private function json(\Glueful\Http\Response $res): array
    {
        return (array) json_decode((string) $res->getContent(), true);
    }

    public function testIndexFiltersByFormKeyAndStatus(): void
    {
        $this->seed('ka', 'unread', 'a@x.test');
        $this->seed('kb', 'unread', 'b@x.test');
        $read = $this->seed('ka', 'read', 'c@x.test');
        $this->repo()->markRead($read);

        $all = $this->json($this->controller()->index(Request::create('/x', 'GET', ['form_key' => 'ka'])));
        self::assertCount(2, $all['data']['submissions']);

        $unread = $this->json($this->controller()->index(
            Request::create('/x', 'GET', ['form_key' => 'ka', 'status' => 'unread']),
        ));
        self::assertCount(1, $unread['data']['submissions']);
        self::assertSame('unread', $unread['data']['submissions'][0]['status']);
    }

    public function testShowReadDeleteAndUnreadCount(): void
    {
        $uuid = $this->seed('ka', 'unread', 'a@x.test');

        $show = $this->controller()->show($uuid);
        self::assertSame(200, $show->getStatusCode());
        self::assertSame('a@x.test', $this->json($show)['data']['submission']['values']['email']);

        self::assertSame(404, $this->controller()->show('missing00000')->getStatusCode());

        self::assertSame(1, $this->json($this->controller()->unreadCount())['data']['count']);
        $this->controller()->read($uuid);
        self::assertSame('read', $this->repo()->find($uuid)->status);
        self::assertSame(0, $this->json($this->controller()->unreadCount())['data']['count']);

        $this->controller()->destroy($uuid);
        self::assertNull($this->repo()->find($uuid));
    }

    public function testExportReturnsCsvWithHeaderAndFieldColumns(): void
    {
        $this->seed('ka', 'unread', 'a@x.test');
        $res = $this->controller()->export(Request::create('/x', 'GET', ['form_key' => 'ka']));
        self::assertInstanceOf(StreamedResponse::class, $res);
        self::assertStringContainsString('text/csv', (string) $res->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $res->headers->get('Content-Disposition'));

        ob_start();
        $res->sendContent();
        $csv = (string) ob_get_clean();
        self::assertStringContainsString('submitted_at,form_name,source_url,ip,user_agent,email,consent', $csv);
        self::assertStringContainsString('a@x.test', $csv);
        self::assertStringContainsString('Yes', $csv); // consent bool → Yes
    }

    public function testFormsListsEachFormWithItsCount(): void
    {
        $this->seed('ka', 'unread', 'a@x.test');
        $this->seed('ka', 'unread', 'b@x.test');
        $this->seed('kb', 'unread', 'c@x.test', name: 'Newsletter');

        $forms = $this->json($this->controller()->forms())['data']['forms'];

        self::assertSame([
            ['form_key' => 'ka', 'form_name' => 'Contact', 'count' => 2],
            ['form_key' => 'kb', 'form_name' => 'Newsletter', 'count' => 1],
        ], $forms);
    }

    public function testBulkDeleteRemovesOnlyTheNamedSubmissions(): void
    {
        $a = $this->seed('ka', 'unread', 'a@x.test');
        $b = $this->seed('ka', 'unread', 'b@x.test');
        $keep = $this->seed('ka', 'unread', 'c@x.test');

        $res = $this->controller()->destroyMany(Request::create(
            '/x',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['uuids' => [$a, $b, 'missing00000']]),
        ));

        self::assertSame(200, $res->getStatusCode());
        self::assertSame(2, $this->json($res)['data']['deleted']);
        self::assertNull($this->repo()->find($a));
        self::assertNotNull($this->repo()->find($keep));
    }

    public function testBulkDeleteNeedsAList(): void
    {
        $res = $this->controller()->destroyMany(Request::create('/x', 'POST', [], [], [], [], '{}'));

        self::assertSame(422, $res->getStatusCode());
    }

    public function testRetentionDeletesOnlySubmissionsPastTheCutoff(): void
    {
        $old = $this->seed('ka', 'read', 'old@x.test', gmdate('Y-m-d H:i:s', time() - 100 * 86400));
        $recent = $this->seed('ka', 'read', 'new@x.test', gmdate('Y-m-d H:i:s', time() - 5 * 86400));

        self::assertSame(1, $this->repo()->deleteOlderThan(gmdate('Y-m-d H:i:s', time() - 30 * 86400)));
        self::assertNull($this->repo()->find($old));
        self::assertNotNull($this->repo()->find($recent));
    }

    public function testThePruneCommandDoesNothingWithoutARetention(): void
    {
        $old = $this->seed('ka', 'read', 'old@x.test', gmdate('Y-m-d H:i:s', time() - 400 * 86400));
        $tester = new \Symfony\Component\Console\Tester\CommandTester(
            $this->container()->get(\Thallo\Core\Content\Console\PruneFormSubmissionsCommand::class),
        );

        self::assertSame(0, $tester->execute([]));
        self::assertNotNull($this->repo()->find($old));

        self::assertSame(0, $tester->execute(['--days' => '30']));
        self::assertNull($this->repo()->find($old));
    }
}
