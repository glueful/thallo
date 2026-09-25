<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Glueful\Cache\CacheStore;
use Thallo\Core\Content\Preview\RegionPreviewStore;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * A regions session's two records (regions-stage spec §4.2): the baseline it was minted with and
 * the working copy its applies build, both living exactly until the token's absolute expiry — no
 * 300-second cap — and never visible to another session. The clock is injected.
 */
final class RegionPreviewStoreTest extends AppTestCase
{
    private int $now = 1_000_000;

    private function store(): RegionPreviewStore
    {
        return new RegionPreviewStore(
            $this->container()->get(CacheStore::class),
            now: fn (): int => $this->now,
        );
    }

    /** @return array<string,mixed> */
    private function regions(string $headerText, ?int $version = 0): array
    {
        return [
            'header' => [
                'blocks' => [
                    ['id' => 'hdr000000001', 'type' => 'rich_text', 'data' => ['body' => "<p>{$headerText}</p>"]],
                ],
                'settings' => [],
                'lock_version' => $version,
            ],
            'footer' => ['blocks' => [], 'settings' => [], 'lock_version' => $version],
        ];
    }

    /** @return array<string,mixed> */
    private function fields(string $headerText): array
    {
        $r = $this->regions($headerText);
        return [
            'header' => ['blocks' => $r['header']['blocks'], 'settings' => []],
            'footer' => ['blocks' => [], 'settings' => []],
        ];
    }

    public function testTheBaselineIsServedUntilTheFirstApply(): void
    {
        $store = $this->store();
        $store->putBaseline('s1', $this->regions('saved'), $this->now + 600);
        $snap = $store->snapshot('s1');
        self::assertSame('baseline', $snap['source']);
        self::assertNull($snap['epoch']);
        self::assertNull($snap['revision']);
        self::assertStringContainsString('saved', json_encode($snap['regions']));

        $result = $store->accept('s1', null, null, $this->fields('edited'), [], $this->now + 600);
        self::assertTrue($result['accepted']);
        self::assertSame(1, $result['revision']);
        $snap = $store->snapshot('s1');
        self::assertSame('working', $snap['source']);
        self::assertSame(1, $snap['revision']);
        self::assertStringContainsString('edited', json_encode($snap['regions']));
    }

    public function testAStalePairIsRefusedWithTheCurrentOne(): void
    {
        $store = $this->store();
        $first = $store->accept('s1', null, null, $this->fields('one'), [], $this->now + 600);
        $refused = $store->accept('s1', null, null, $this->fields('two'), [], $this->now + 600);
        self::assertFalse($refused['accepted']);
        self::assertSame($first['epoch'], $refused['epoch']);
        self::assertSame(1, $refused['revision']);
    }

    public function testTheWorkingCopyOutlivesFiveMinutesUntilTheTokenExpires(): void
    {
        $store = $this->store();
        $exp = $this->now + 600;
        $store->putBaseline('s1', $this->regions('saved'), $exp);
        $store->accept('s1', null, null, $this->fields('edited'), [], $exp);

        $this->now += 301;
        $snap = $store->snapshot('s1');
        self::assertSame('working', $snap['source'], 'the edits were dropped after five minutes');
        self::assertStringContainsString('edited', json_encode($snap['regions']));

        $this->now = $exp + 1;
        self::assertNull($store->snapshot('s1'), 'the session outlived its token');
        self::assertNull($store->baseline('s1'));
    }

    public function testClearIfPairNeedsBothHalves(): void
    {
        $store = $this->store();
        $accepted = $store->accept('s1', null, null, $this->fields('one'), [], $this->now + 600);
        $epoch = $accepted['epoch'];

        self::assertFalse($store->clearIfPair('s1', 'another-epoch', 1));
        self::assertNotNull($store->current('s1'));
        self::assertFalse($store->clearIfPair('s1', $epoch, 2));
        self::assertNotNull($store->current('s1'));
        self::assertTrue($store->clearIfPair('s1', $epoch, 1));
        self::assertNull($store->current('s1'));
    }

    public function testSessionsAreIsolated(): void
    {
        $store = $this->store();
        $store->putBaseline('s1', $this->regions('one'), $this->now + 600);
        $store->accept('s1', null, null, $this->fields('one-edited'), [], $this->now + 600);
        $store->putBaseline('s2', $this->regions('two'), $this->now + 600);

        self::assertStringContainsString('two', json_encode($store->snapshot('s2')['regions']));
        self::assertSame('baseline', $store->snapshot('s2')['source']);
        self::assertStringContainsString('one-edited', json_encode($store->snapshot('s1')['regions']));
        self::assertNull($store->current('s2'));
    }
}
