<?php

declare(strict_types=1);

namespace App\Tests\Integration\Forms;

use App\Content\Forms\FormSubmission;
use App\Content\Forms\FormSubmissionRepository;
use App\Http\Controllers\FormSubmissionsController;
use App\Tests\Support\AppTestCase;
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

    private function seed(string $key, string $status, string $email): string
    {
        return $this->repo()->store(new FormSubmission(
            uuid: '',
            formKey: $key,
            formName: 'Contact',
            sourceUrl: '/contact',
            fieldsSnapshot: [['key' => 'email', 'label' => 'Email', 'type' => 'email']],
            values: ['email' => $email, 'consent' => true],
            descriptorVersion: 1,
            status: $status,
            ip: '127.0.0.1',
            userAgent: 'test',
            submittedAt: '2026-07-09 10:00:00',
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
}
