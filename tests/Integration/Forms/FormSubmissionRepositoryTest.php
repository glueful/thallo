<?php

declare(strict_types=1);

namespace App\Tests\Integration\Forms;

use App\Content\Forms\FormSubmission;
use App\Content\Forms\FormSubmissionRepository;
use App\Tests\Support\AppTestCase;

final class FormSubmissionRepositoryTest extends AppTestCase
{
    private function repo(): FormSubmissionRepository
    {
        return $this->container()->get(FormSubmissionRepository::class);
    }

    private function make(string $key = 'k1', string $status = 'unread'): FormSubmission
    {
        return new FormSubmission(
            uuid: '',
            formKey: $key,
            formName: 'Contact',
            sourceUrl: '/contact',
            fieldsSnapshot: [['key' => 'email', 'label' => 'Email', 'type' => 'email']],
            values: ['email' => 'a@b.test'],
            descriptorVersion: 1,
            status: $status,
            ip: '127.0.0.1',
            userAgent: 'test',
            submittedAt: '2026-07-09 10:00:00',
        );
    }

    public function testStoreListAndUnreadCount(): void
    {
        $uuid = $this->repo()->store($this->make());
        self::assertNotSame('', $uuid);
        self::assertGreaterThanOrEqual(1, $this->repo()->unreadCount());
        $rows = $this->repo()->list(['form_key' => 'k1']);
        self::assertNotEmpty($rows);
        self::assertSame('a@b.test', $rows[0]->values['email']); // JSON round-trips
    }

    public function testMarkReadAndDelete(): void
    {
        $uuid = $this->repo()->store($this->make());
        $this->repo()->markRead($uuid);
        self::assertSame('read', $this->repo()->find($uuid)->status);
        $this->repo()->delete($uuid);
        self::assertNull($this->repo()->find($uuid));
    }

    public function testListFiltersByStatusAndExportYieldsRows(): void
    {
        $this->repo()->store($this->make('k2', 'unread'));
        $read = $this->repo()->store($this->make('k2', 'read'));
        $this->repo()->markRead($read);

        $unread = $this->repo()->list(['form_key' => 'k2', 'status' => 'unread']);
        self::assertNotEmpty($unread);
        foreach ($unread as $row) {
            self::assertSame('unread', $row->status);
        }

        $exported = iterator_to_array($this->repo()->export(['form_key' => 'k2']));
        self::assertGreaterThanOrEqual(2, count($exported));
    }
}
