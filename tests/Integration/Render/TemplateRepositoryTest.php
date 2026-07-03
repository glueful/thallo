<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Render\Templates\TemplateRepository;

final class TemplateRepositoryTest extends LemmaTestCase
{
    private function repo(): TemplateRepository
    {
        return new TemplateRepository($this->connection());
    }

    public function testSaveCreatesRowAndVersionAndOverrideMapSeesIt(): void
    {
        $r = $this->repo();
        $ids = $r->save('default', 'entry.twig', 'v1 source', 'user00000001');
        self::assertSame(12, strlen($ids['template_uuid']));

        self::assertSame(['entry.twig' => $ids['version_uuid']], $r->overrideMap('default'));
        self::assertSame([], $r->overrideMap('other-theme')); // per-theme keying

        $current = $r->findCurrentSource('default', 'entry.twig');
        self::assertSame('v1 source', $current['source']);
        self::assertSame($ids['version_uuid'], $current['version_uuid']);
    }

    public function testHistoryIsAppendOnlyAndDeleteDeactivatesPreservingIt(): void
    {
        $r = $this->repo();
        $v1 = $r->save('default', 'entry.twig', 'one', null);
        $v2 = $r->save('default', 'entry.twig', 'two', 'user00000001');
        self::assertSame($v1['template_uuid'], $v2['template_uuid']); // same row
        self::assertNotSame($v1['version_uuid'], $v2['version_uuid']);

        $versions = $r->versions('default', 'entry.twig');
        self::assertCount(2, $versions);
        self::assertTrue($versions[0]['current']);   // newest first
        self::assertFalse($versions[1]['current']);

        self::assertTrue($r->deactivate('default', 'entry.twig'));
        self::assertFalse($r->deactivate('default', 'entry.twig')); // already inactive
        self::assertSame([], $r->overrideMap('default'));            // loader-invisible
        self::assertNull($r->findCurrentSource('default', 'entry.twig'));
        self::assertCount(2, $r->versions('default', 'entry.twig')); // history preserved

        // Re-create REACTIVATES the old row; history continues.
        $v3 = $r->save('default', 'entry.twig', 'three', null);
        self::assertSame($v1['template_uuid'], $v3['template_uuid']);
        self::assertCount(3, $r->versions('default', 'entry.twig'));
        self::assertSame('three', $r->findCurrentSource('default', 'entry.twig')['source']);
    }

    public function testFindVersionScopedToThemeAndPath(): void
    {
        $r = $this->repo();
        $ids = $r->save('default', 'entry.twig', 'body', 'user00000001');
        $found = $r->findVersion('default', 'entry.twig', $ids['version_uuid']);
        self::assertSame('body', $found['source']);
        self::assertSame('user00000001', $found['created_by']);
        self::assertNull($r->findVersion('other', 'entry.twig', $ids['version_uuid']));
        self::assertNull($r->findVersion('default', 'other.twig', $ids['version_uuid']));

        self::assertSame(['entry.twig'], array_keys($r->listActive('default')));
    }
}
