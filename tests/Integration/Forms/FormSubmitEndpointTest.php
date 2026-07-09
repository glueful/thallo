<?php

declare(strict_types=1);

namespace App\Tests\Integration\Forms;

use App\Content\Forms\DefaultFormSealer;
use App\Content\Forms\FieldDef;
use App\Content\Forms\FormSubmissionRepository;
use App\Tests\Support\AppTestCase;
use Glueful\Encryption\EncryptionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Contracts\Content\FormSealer;

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

    public function testEmailOnlyDeliveryDoesNotStore(): void
    {
        $token = $this->sealContactForm('owner@site.test', ['delivery' => 'email_only']);
        $res = $this->postForm([
            '_form' => $token, '_t' => (string) (time() - 5),
            'name' => 'Zoe', 'email' => 'zoe@x.test', 'message' => 'hi',
        ], json: true);
        self::assertTrue($this->json($res)['ok']);              // still a success to the visitor
        self::assertSame(0, $this->countSubmissions('zoe@x.test')); // email-only: nothing stored
    }

    public function testExpiredDescriptorReturnsReloadMessage(): void
    {
        $res = $this->postForm(['_form' => $this->expiredToken(), '_t' => (string) (time() - 5)], json: true);
        self::assertFalse($this->json($res)['ok']);
        self::assertStringContainsString('expired', strtolower((string) $this->json($res)['error']));
    }
}
