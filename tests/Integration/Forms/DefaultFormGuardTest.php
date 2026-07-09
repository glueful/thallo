<?php

declare(strict_types=1);

namespace App\Tests\Integration\Forms;

use App\Content\Forms\FieldDef;
use App\Content\Forms\FormDescriptor;
use App\Content\Forms\Spam\DefaultFormGuard;
use App\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;

final class DefaultFormGuardTest extends AppTestCase
{
    private function descriptor(): FormDescriptor
    {
        return new FormDescriptor(
            1,
            'k1',
            'Contact',
            [new FieldDef('email', 'Email', 'email', true, null, null, [])],
            'a@b.test',
            'ok',
            null,
            honeypotField: 'website_x',
            minSeconds: 2,
            spamVersion: 1,
            issuedAt: time(),
        );
    }

    private function guard(): DefaultFormGuard
    {
        return $this->container()->get(DefaultFormGuard::class);
    }

    public function testHoneypotFilledIsRejected(): void
    {
        $req = Request::create('/_forms/submit', 'POST', ['website_x' => 'bot', '_t' => (string) (time() - 10)]);
        self::assertFalse($this->guard()->check($req, $this->descriptor())->passed());
        self::assertSame('honeypot', $this->guard()->check($req, $this->descriptor())->reason());
    }

    public function testTooFastIsRejected(): void
    {
        $req = Request::create('/_forms/submit', 'POST', ['_t' => (string) time()]); // 0s elapsed < 2
        self::assertSame('time_trap', $this->guard()->check($req, $this->descriptor())->reason());
    }

    public function testCleanSubmitPasses(): void
    {
        $req = Request::create('/_forms/submit', 'POST', ['_t' => (string) (time() - 10)]);
        $req->server->set('REMOTE_ADDR', '10.0.0.99'); // unique IP so rate limit is clean
        self::assertTrue($this->guard()->check($req, $this->descriptor())->passed());
    }
}
