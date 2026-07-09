<?php

declare(strict_types=1);

namespace App\Tests\Integration\Forms;

use App\Content\Forms\DefaultFormSealer;
use App\Content\Forms\FieldDef;
use App\Content\Forms\FormDescriptor;
use App\Tests\Support\AppTestCase;
use Glueful\Encryption\EncryptionService;

final class FormSealerTest extends AppTestCase
{
    private function sealer(int $maxAge = 1209600, int $cacheTtl = 3600, int $buffer = 3600): DefaultFormSealer
    {
        $enc = $this->container()->get(EncryptionService::class);
        // In-test derivation: fixed one-field form so Task 1 needn't depend on Task 2.
        $derive = fn (array $data): array => [new FieldDef('email', 'Email', 'email', true, null, null, [])];
        return new DefaultFormSealer(
            $enc,
            $derive,
            cacheTtl: $cacheTtl,
            maxAge: $maxAge,
            buffer: $buffer,
            defaultRecipient: '',
            minSeconds: 2,
        );
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function block(string $id, array $data = ['recipient' => 'a@b.test']): array
    {
        return ['id' => $id, 'type' => 'form', 'data' => $data];
    }

    public function testDescribeAndOpenRoundTrip(): void
    {
        $block = $this->block('abc123', ['recipient' => 'owner@site.test', 'form_name' => 'Contact']);
        $sf = $this->sealer()->describe($block, entry: ['uuid' => 'e-1'], currentPath: '/contact', regionSlug: null);
        self::assertNotNull($sf);
        self::assertIsString($sf->token);
        // The render path reads the descriptor DIRECTLY off the SealedForm — no re-open.
        self::assertSame('owner@site.test', $sf->descriptor->recipient);
        self::assertSame(hash('sha256', 'entry:e-1|abc123'), $sf->descriptor->formKey);
        self::assertSame(2, $sf->descriptor->minSeconds); // time-trap armed at seal time

        // The submit path re-opens the token and gets the same descriptor.
        $d = $this->sealer()->open($sf->token);
        self::assertInstanceOf(FormDescriptor::class, $d);
        self::assertSame('owner@site.test', $d->recipient);
        self::assertCount(1, $d->fields);
        // Delivery defaults to store_and_email and survives the seal/open round-trip.
        self::assertTrue($d->shouldStore());
    }

    public function testEmailOnlyDeliverySealsAndOpens(): void
    {
        $block = $this->block('e1', ['recipient' => 'owner@site.test', 'delivery' => 'email_only']);
        $sf = $this->sealer()->describe($block, null, '/contact', null);
        self::assertFalse($sf->descriptor->shouldStore());
        self::assertFalse($this->sealer()->open($sf->token)->shouldStore());
    }

    public function testTamperedTokenOpensToNull(): void
    {
        $token = $this->sealer()->describe($this->block('x'), null, '/c', null)->token;
        // Flip a character in the MIDDLE of the token, not its final base64 group: the
        // last group's trailing bits can alias to the same decoded byte, so GCM would
        // still verify. A middle byte change is deterministic — auth fails, open() → null.
        $i = intdiv(strlen($token), 2);
        $repl = $token[$i] === 'A' ? 'B' : 'A';
        self::assertNull($this->sealer()->open(substr_replace($token, $repl, $i, 1)));
    }

    public function testExpiredDescriptorOpensToNull(): void
    {
        // Drive lifetime negative: max(maxAge, cacheTtl+buffer) must ALSO be negative,
        // else the cache-TTL floor (the P1 invariant) correctly keeps the token valid.
        $expired = $this->sealer(maxAge: -100000, cacheTtl: -100000, buffer: 0);
        $token = $expired->describe($this->block('x'), null, '/c', null)->token;
        self::assertNull($expired->open($token));
    }

    public function testUnroutableFormRefusesToDescribe(): void
    {
        // No block recipient, no default recipient => null (no SealedForm, no descriptor).
        self::assertNull($this->sealer()->describe($this->block('x', []), null, '/c', null));
    }

    public function testSourceIdentityFallbackOrder(): void
    {
        $s = $this->sealer();
        $entry = ['uuid' => 'e-9'];
        $kEntry = $s->describe($this->block('blk'), $entry, '/p', null)->descriptor->formKey;
        $kRegion = $s->describe($this->block('blk'), null, '/p', 'header')->descriptor->formKey;
        // Same block id, different source context => different form_key.
        self::assertNotSame($kEntry, $kRegion);
    }

    public function testRedirectUrlAllowsRootRelativeOnly(): void
    {
        $seal = fn (string $url) => $this->sealer()->describe(
            ['id' => 'r', 'type' => 'form', 'data' => ['recipient' => 'a@b.test', 'redirect_url' => $url]],
            null,
            '/c',
            null,
        )->descriptor->redirectUrl;

        self::assertSame('/thanks', $seal('/thanks'));
        self::assertNull($seal('https://evil.test'));
        self::assertNull($seal('//evil.test'));
        self::assertNull($seal('contact/thanks'));
        self::assertNull($seal('?thanks=1'));
        self::assertNull($seal('#thanks'));
    }
}
