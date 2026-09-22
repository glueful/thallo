<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Forms;

use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Services\NotificationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Thallo\Core\Content\Forms\FieldDef;
use Thallo\Core\Content\Forms\FormDescriptor;
use Thallo\Core\Content\Forms\FormMailSender;
use Thallo\Core\Content\Forms\FormNotifier;
use Thallo\Core\Content\Forms\NotificationFormMailSender;

final class FormNotifierTest extends TestCase
{
    private function descriptor(string $recipient = 'owner@site.test'): FormDescriptor
    {
        return new FormDescriptor(
            1,
            'k1',
            'Contact',
            [
                new FieldDef('name', 'Full name', 'text', true, null, null, []),
                new FieldDef('email', 'Email', 'email', true, null, null, []),
            ],
            $recipient,
            'ok',
            null,
            honeypotField: 'website_x',
            minSeconds: 2,
            spamVersion: 1,
            issuedAt: time(),
        );
    }

    public function testNoopsWithoutASender(): void
    {
        $notifier = new FormNotifier(null, new NullLogger());
        $sent = $notifier->notify($this->descriptor(), ['name' => 'Ada', 'email' => 'ada@x.test'], '/contact');
        self::assertFalse($sent, 'nothing to send with: the caller must know nothing went');
    }

    public function testSendsToRecipientWithLabelledBody(): void
    {
        $sender = new class implements FormMailSender {
            /** @var array{to:string,subject:string,body:string}|null */
            public ?array $sent = null;

            public function send(string $to, string $subject, string $body): void
            {
                $this->sent = ['to' => $to, 'subject' => $subject, 'body' => $body];
            }
        };
        $notifier = new FormNotifier($sender, new NullLogger());
        $values = ['name' => 'Ada', 'email' => 'ada@x.test'];
        self::assertTrue($notifier->notify($this->descriptor(), $values, '/contact'));

        self::assertNotNull($sender->sent);
        self::assertSame('owner@site.test', $sender->sent['to']);
        self::assertStringContainsString('Contact', $sender->sent['subject']);
        self::assertStringContainsString('Full name', $sender->sent['body']); // label, not key
        self::assertStringContainsString('Ada', $sender->sent['body']);
        self::assertStringContainsString('/contact', $sender->sent['body']);
    }

    public function testThrowingSenderIsSwallowed(): void
    {
        $sender = new class implements FormMailSender {
            public function send(string $to, string $subject, string $body): void
            {
                throw new \RuntimeException('smtp down');
            }
        };
        $notifier = new FormNotifier($sender, new NullLogger());
        $sent = $notifier->notify($this->descriptor(), ['name' => 'Ada'], null);
        self::assertFalse($sent, 'never fatal, but reported');
    }

    public function testInvalidRecipientIsNotSent(): void
    {
        $sender = new class implements FormMailSender {
            public bool $called = false;

            public function send(string $to, string $subject, string $body): void
            {
                $this->called = true;
            }
        };
        $notifier = new FormNotifier($sender, new NullLogger());
        self::assertFalse($notifier->notify($this->descriptor('not-an-email'), ['name' => 'Ada'], null));
        self::assertFalse($sender->called);
    }

    public function testTheDefaultSenderSendsThroughTheEmailChannelAndReportsAFailure(): void
    {
        // No FormMailSender was ever bound, so the default install sent nothing while the block
        // promised an email. The default is now the notification service's email channel — the
        // path signup's mail takes — and a channel that did not deliver is a failure, not a send.
        $calls = [];
        $service = $this->createMock(NotificationService::class);
        $service->method('send')->willReturnCallback(
            function (string $type, Notifiable $to, string $subject, array $data, array $options) use (&$calls): array {
                $calls[] = [$type, $to->routeNotificationFor('email'), $subject, $data, $options];
                return ['status' => 'success', 'channels' => ['email' => ['status' => 'success']]];
            },
        );
        (new NotificationFormMailSender($service))->send('owner@site.test', 'New Contact submission', "Name: Ada");
        self::assertSame('form_submission', $calls[0][0]);
        self::assertSame('owner@site.test', $calls[0][1]);
        self::assertSame('New Contact submission', $calls[0][2]);
        self::assertSame("Name: Ada", $calls[0][3]['message']);
        self::assertSame(['email'], $calls[0][4]['channels']);

        $failing = $this->createMock(NotificationService::class);
        $failing->method('send')->willReturn(['status' => 'failed', 'channels' => ['email' => ['status' => 'failed']]]);
        $this->expectException(\RuntimeException::class);
        (new NotificationFormMailSender($failing))->send('owner@site.test', 's', 'b');
    }
}
