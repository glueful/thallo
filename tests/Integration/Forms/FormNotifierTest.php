<?php

declare(strict_types=1);

namespace App\Tests\Integration\Forms;

use App\Content\Forms\FieldDef;
use App\Content\Forms\FormDescriptor;
use App\Content\Forms\FormMailSender;
use App\Content\Forms\FormNotifier;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

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
        $notifier->notify($this->descriptor(), ['name' => 'Ada', 'email' => 'ada@x.test'], '/contact');
        $this->addToAssertionCount(1); // reached here without throwing
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
        $notifier->notify($this->descriptor(), ['name' => 'Ada', 'email' => 'ada@x.test'], '/contact');

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
        $notifier->notify($this->descriptor(), ['name' => 'Ada'], null);
        $this->addToAssertionCount(1); // best-effort: never fatal to the caller
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
        $notifier->notify($this->descriptor('not-an-email'), ['name' => 'Ada'], null);
        self::assertFalse($sender->called);
    }
}
