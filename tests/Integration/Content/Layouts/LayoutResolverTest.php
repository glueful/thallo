<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Layouts;

use Thallo\Contracts\Layouts\LayoutReader;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Content\Layouts\LayoutWriteLock;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * The layout reader (type layouts spec §7.4): one cached answer per subject — a layout, or "none" —
 * so a render never queries the table twice for the same page kind. Writers forget the answer
 * after committing, so a first layout or a new version is found on the next render.
 */
final class LayoutResolverTest extends AppTestCase
{
    private function resolver(): LayoutResolver
    {
        return $this->container()->get(LayoutResolver::class);
    }

    private function saveDirect(int $expected, ?array $blocks = null): void
    {
        $lock = $this->container()->get(LayoutWriteLock::class);
        $repo = $this->container()->get(LayoutRepository::class);
        $lock->within('entry', 'post', fn (): int => $blocks === null
            ? $repo->tombstone('entry', 'post', $expected, null)
            : $repo->saveExpected('entry', 'post', $blocks, ['width' => 'contained'], $expected, null));
    }

    protected function tearDown(): void
    {
        $this->resolver()->forget('entry', 'post');
        parent::tearDown();
    }

    public function testTheResolverIsTheBoundLayoutReader(): void
    {
        self::assertInstanceOf(LayoutResolver::class, $this->container()->get(LayoutReader::class));
    }

    public function testNoneIsCachedAndAFirstSaveIsFoundAfterForget(): void
    {
        $this->resolver()->forget('entry', 'post');
        self::assertNull($this->resolver()->for('entry', 'post'));
        $this->saveDirect(0, [['type' => 'heading', 'data' => ['text' => 'Hi'], 'settings' => []]]);
        self::assertNull($this->resolver()->for('entry', 'post'), 'the cached "none" stands until forgotten');
        $this->resolver()->forget('entry', 'post');
        $layout = $this->resolver()->for('entry', 'post');
        self::assertSame('Hi', $layout['blocks'][0]['data']['text']);
        self::assertSame(['width' => 'contained'], $layout['settings']);
        self::assertSame(1, $layout['lock_version']);
    }

    public function testATombstoneReadsAsNone(): void
    {
        $this->saveDirect(0, [['type' => 'heading', 'data' => ['text' => 'Hi'], 'settings' => []]]);
        $this->saveDirect(1);
        $this->resolver()->forget('entry', 'post');
        self::assertNull($this->resolver()->for('entry', 'post'));
    }
}
