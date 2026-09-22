<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Forms;

use Glueful\Encryption\EncryptionService;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Contracts\Content\FormSealer;
use Thallo\Core\Content\Forms\DefaultFormSealer;
use Thallo\Core\Content\Forms\FieldDef;
use Thallo\Core\Content\Forms\FormMailSender;
use Thallo\Core\Content\Forms\FormNotifier;
use Thallo\Core\Content\Forms\FormSubmissionRepository;
use Thallo\Core\Content\Forms\NotificationFormMailSender;
use Thallo\Core\Content\Forms\Spam\FormSubmissionGuard;
use Thallo\Core\Http\Controllers\FormSubmitController;
use Thallo\Core\Tests\Support\AppTestCase;

final class FormSubmitEndpointTest extends AppTestCase
{
    /**
     * Seal a real contact-form descriptor through the bound sealer and return its token.
     *
     * @param array<string,mixed> $extra extra block-data keys (e.g. delivery)
     */
    private function sealContactForm(string $recipient, array $extra = []): string
    {
        $sealer = $this->container()->get(FormSealer::class);
        $sealed = $sealer->describe(
            ['id' => 'c1', 'type' => 'form', 'data' => ['recipient' => $recipient] + $extra],
            null,
            '/contact',
            null,
        );
        self::assertNotNull($sealed);
        return $sealed->token;
    }

    /** A token whose descriptor is already expired (negative lifetime floor). */
    private function expiredToken(): string
    {
        $enc = $this->container()->get(EncryptionService::class);
        $derive = fn (array $data): array => [new FieldDef('email', 'Email', 'email', true, null, null, [])];
        $sealer = new DefaultFormSealer(
            $enc,
            $derive,
            cacheTtl: -100000,
            maxAge: -100000,
            buffer: 0,
            defaultRecipient: '',
            minSeconds: 2,
        );
        $block = ['id' => 'x', 'type' => 'form', 'data' => ['recipient' => 'a@b.test']];
        return $sealer->describe($block, null, '/c', null)->token;
    }

    /** @param array<string,string> $data */
    private function postForm(array $data, bool $json): Response
    {
        $req = Request::create('/_forms/submit', 'POST', $data);
        $req->server->set('REMOTE_ADDR', '10.0.0.' . random_int(1, 250)); // spread IPs so rate limit stays clean
        if ($json) {
            $req->headers->set('Accept', 'application/json');
        }
        return $this->handle($req);
    }

    /** @return array<string,mixed> */
    private function json(Response $res): array
    {
        return (array) json_decode((string) $res->getContent(), true);
    }

    private function countSubmissions(string $email): int
    {
        $repo = $this->container()->get(FormSubmissionRepository::class);
        $n = 0;
        foreach ($repo->list() as $s) {
            if (($s->values['email'] ?? null) === $email) {
                $n++;
            }
        }
        return $n;
    }

    public function testValidSubmitStoresAndReturnsJsonSuccess(): void
    {
        $token = $this->sealContactForm('owner@site.test');
        $res = $this->postForm([
            '_form' => $token, '_t' => (string) (time() - 5),
            'name' => 'Ada', 'email' => 'ada@x.test', 'message' => 'hello',
        ], json: true);
        self::assertSame(200, $res->getStatusCode());
        self::assertTrue($this->json($res)['ok']);
        self::assertSame(1, $this->countSubmissions('ada@x.test'));
    }

    public function testValidationErrorReturnsFieldErrorsJson(): void
    {
        $token = $this->sealContactForm('owner@site.test');
        $res = $this->postForm([
            '_form' => $token, '_t' => (string) (time() - 5),
            'name' => '', 'email' => 'bad', 'message' => '',
        ], json: true);
        $body = $this->json($res);
        self::assertFalse($body['ok']);
        self::assertArrayHasKey('email', $body['errors']);
    }

    public function testSpamRejectReturnsGenericSuccessAndStoresNothing(): void
    {
        $token = $this->sealContactForm('owner@site.test');
        $res = $this->postForm([
            '_form' => $token, '_t' => (string) time(), // too fast
            'name' => 'X', 'email' => 'x@y.test', 'message' => 'hi',
        ], json: true);
        self::assertTrue($this->json($res)['ok']);          // generic success — bots learn nothing
        self::assertSame(0, $this->countSubmissions('x@y.test'));
    }

    public function testNoJsPrgRedirectsBackWithSuccessFlag(): void
    {
        $token = $this->sealContactForm('owner@site.test');
        $res = $this->postForm([
            '_form' => $token, '_return' => '/contact', '_t' => (string) (time() - 5),
            'name' => 'A', 'email' => 'a@x.test', 'message' => 'hi',
        ], json: false);
        self::assertSame(303, $res->getStatusCode());
        self::assertStringContainsString('/contact?form_ok=', (string) $res->headers->get('Location'));
    }

    public function testTheContainerBindsTheEmailChannelSenderByDefault(): void
    {
        $notifier = $this->container()->get(FormNotifier::class);
        $sender = (new \ReflectionProperty(FormNotifier::class, 'sender'))->getValue($notifier);
        self::assertInstanceOf(NotificationFormMailSender::class, $sender);
    }

    public function testEmailOnlyStoresNothingWhenTheMailWent(): void
    {
        $token = $this->sealContactForm('owner@site.test', ['delivery' => 'email_only']);
        $sent = [];
        $res = $this->submitThrough($this->notifierThat(sends: true, log: $sent), [
            '_form' => $token, '_t' => (string) (time() - 5),
            'name' => 'Zoe', 'email' => 'zoe@x.test', 'message' => 'hi',
        ]);
        self::assertTrue($this->json($res)['ok']);
        self::assertCount(1, $sent, 'the notification is the only copy, so it had to go');
        self::assertSame(0, $this->countSubmissions('zoe@x.test'));
    }

    public function testEmailOnlyKeepsTheSubmissionWhenTheMailDidNotGo(): void
    {
        // "Email only" used to mean: if the mail fails, the message is gone. With no mailer the
        // default install could send nothing, so every such submission was lost without a trace.
        $token = $this->sealContactForm('owner@site.test', ['delivery' => 'email_only']);
        $sent = [];
        $res = $this->submitThrough($this->notifierThat(sends: false, log: $sent), [
            '_form' => $token, '_t' => (string) (time() - 5),
            'name' => 'Yan', 'email' => 'yan@x.test', 'message' => 'hi',
        ]);
        self::assertTrue($this->json($res)['ok']);
        self::assertSame(1, $this->countSubmissions('yan@x.test'), 'kept, so it can still be read in the admin');
    }

    /** @param list<string> $log */
    private function notifierThat(bool $sends, array &$log): FormNotifier
    {
        $sender = new class ($sends, $log) implements FormMailSender {
            /** @param list<string> $log */
            public function __construct(private bool $sends, private array &$log)
            {
            }

            public function send(string $to, string $subject, string $body): void
            {
                if (!$this->sends) {
                    throw new \RuntimeException('mail transport unavailable');
                }
                $this->log[] = $to;
            }
        };
        return new FormNotifier($sender, new NullLogger());
    }

    /** @param array<string,string> $data */
    private function submitThrough(FormNotifier $notifier, array $data): Response
    {
        $c = $this->container();
        $controller = new FormSubmitController(
            $c->get(FormSealer::class),
            $c->get(FormSubmissionGuard::class),
            $c->get(FormSubmissionRepository::class),
            $notifier,
            new NullLogger(),
        );
        $req = Request::create('/_forms/submit', 'POST', $data);
        $req->server->set('REMOTE_ADDR', '10.0.1.' . random_int(1, 250));
        $req->headers->set('Accept', 'application/json');
        return $controller->submit($req);
    }

    public function testExpiredDescriptorReturnsReloadMessage(): void
    {
        $res = $this->postForm(['_form' => $this->expiredToken(), '_t' => (string) (time() - 5)], json: true);
        self::assertFalse($this->json($res)['ok']);
        self::assertStringContainsString('expired', strtolower((string) $this->json($res)['error']));
    }
}
