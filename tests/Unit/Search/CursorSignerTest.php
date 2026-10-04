<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Search\SearchAudience;
use Thallo\Search\Query\Cursor;
use Thallo\Search\Query\CursorBinding;
use Thallo\Search\Query\CursorSigner;
use Thallo\Search\Query\SearchInput;
use Thallo\Search\Query\Surface;

/**
 * A cursor is a signed continuation position, bound to everything that shaped the query (search
 * block spec §3.4): the normalised q, scope or kind, type, locale, workspace and audience. A cursor
 * presented under any other binding, or tampered with, is no cursor at all.
 */
final class CursorSignerTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $signer = new CursorSigner('k');
        $binding = $this->binding([]);
        $cursor = $signer->verify($signer->sign(new Cursor($binding, 30)), $binding);
        self::assertNotNull($cursor);
        self::assertSame(30, $cursor->rawOffset);
    }

    public function testAnyOtherBindingIsRefused(): void
    {
        $signer = new CursorSigner('k');
        $token = $signer->sign(new Cursor($this->binding([]), 10));
        foreach (
            [
                $this->binding(['q' => 'lily']),
                $this->binding(['kind' => 'all']),
                $this->binding(['type' => 'page']),
                $this->binding(['locale' => 'fr-CA']),
                $this->binding([], 'other-workspace'),
                $this->binding([], 'ws', SearchAudience::public()),
            ] as $other
        ) {
            self::assertNull($signer->verify($token, $other));
        }
    }

    public function testTamperingAndJunkAreRefused(): void
    {
        $signer = new CursorSigner('k');
        $binding = $this->binding([]);
        $token = $signer->sign(new Cursor($binding, 10));
        $flipped = substr($token, 0, -1) . (substr($token, -1) === 'A' ? 'B' : 'A');
        self::assertNull($signer->verify($flipped, $binding));
        self::assertNull((new CursorSigner('other'))->verify($token, $binding));
        foreach ([null, 7, ['a'], '', str_repeat('a', 600), 'no-dot', 'a.b.c'] as $junk) {
            self::assertNull($signer->verify($junk, $binding));
        }
    }

    /** @param array<string, mixed> $query */
    private function binding(array $query, string $workspace = 'ws', ?SearchAudience $audience = null): string
    {
        $defaults = ['q' => 'rose', 'locale' => 'en'] + (($query['kind'] ?? null) === 'all' ? [] : ['type' => 'post']);
        $in = SearchInput::from($query + $defaults, Surface::Api, ['en', 'fr-CA'], 'en', ['entries', 'products']);
        return CursorBinding::of($in, $workspace, $audience ?? SearchAudience::apiKey(['read:content']));
    }
}
