<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Contracts\Authoring\ContentUpserter;
use Thallo\Contracts\Authoring\ContentWriter;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Seo\RedirectRepository;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * An importer run on every deploy has to land on the entries it made last time. ContentWriter
 * only ever creates; this is the rest of what a repeatable import needs, as a contract packs may
 * use: find an entry by its URL or by a field it carries, read what it holds now, replace its
 * draft, and give it its URL — where a changed URL leaves a redirect behind, as it does in the admin.
 */
final class ContentUpserterTest extends AppTestCase
{
    private function upserter(): ContentUpserter
    {
        return $this->container()->get(ContentUpserter::class);
    }

    private function type(): string
    {
        return (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'upsdocs',
            'name' => 'Docs',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'source_path', 'type' => 'string'],
            ],
        ]);
    }

    public function testAnEntryIsFoundByItsUrlOrByAFieldItCarries(): void
    {
        $type = $this->type();
        $writer = $this->container()->get(ContentWriter::class);
        $entry = $writer->createDraft($type, 'en', ['title' => 'Install', 'source_path' => 'docs/install.md']);
        $other = $writer->createDraft($type, 'en', ['title' => 'Other', 'source_path' => 'docs/other.md']);
        $up = $this->upserter();

        self::assertNull($up->findBySlug($type, 'en', 'install'), 'no URL yet');
        $up->assignSlug($entry, $type, 'en', 'install');
        self::assertSame($entry, $up->findBySlug($type, 'en', 'install'));
        self::assertSame($entry, $up->findByField($type, 'en', 'source_path', 'docs/install.md'));
        self::assertSame($other, $up->findByField($type, 'en', 'source_path', 'docs/other.md'));
        self::assertNull($up->findByField($type, 'en', 'source_path', 'docs/none.md'));
        // A value is matched as a value, never as a pattern or a fragment of SQL.
        self::assertNull($up->findByField($type, 'en', 'source_path', "docs/%"));
        self::assertNull($up->findByField($type, 'en', "source_path' OR '1'='1", 'x'));
    }

    public function testItReadsWhatAnEntryHoldsAndReplacesItsDraft(): void
    {
        $type = $this->type();
        $entry = $this->container()->get(ContentWriter::class)->createDraft($type, 'en', ['title' => 'Install']);
        $up = $this->upserter();
        $up->assignSlug($entry, $type, 'en', 'install');

        $now = $up->current($entry, 'en');
        self::assertSame(['title' => 'Install'], $now['fields']);
        self::assertSame('install', $now['slug']);
        self::assertFalse($now['published']);

        $up->updateDraft($entry, 'en', ['title' => 'Installing', 'source_path' => 'docs/install.md']);
        self::assertSame('Installing', $up->current($entry, 'en')['fields']['title']);
        // Published directly: the test app has the review workflow on, which gates a plain publish.
        $types = new ContentTypeRepository($this->connection());
        (new \Thallo\Core\Content\Services\PublishService(
            $this->appContext(),
            new \Thallo\Core\Content\Repositories\EntryRepository($this->connection(), $this->appContext(), $types),
            new \Thallo\Core\Content\Repositories\VersionRepository($this->connection()),
            $types,
            new \Thallo\Core\Content\Validation\FieldValidator($this->connection()),
            new \Thallo\Core\Content\Repositories\ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, 'en', 'user00000001');
        self::assertTrue($up->current($entry, 'en')['published']);
        self::assertNull($up->current('nope00000000', 'en'));

        // The same validation as every other write: a title is text, in a draft too.
        $this->expectException(\Thallo\Contracts\Authoring\ValidationFailed::class);
        $up->updateDraft($entry, 'en', ['title' => ['not', 'text']]);
    }

    public function testAChangedUrlLeavesARedirectAndATakenOneIsRefused(): void
    {
        $type = $this->type();
        $writer = $this->container()->get(ContentWriter::class);
        $entry = $writer->createDraft($type, 'en', ['title' => 'Install']);
        $taken = $writer->createDraft($type, 'en', ['title' => 'Taken']);
        $up = $this->upserter();
        $up->assignSlug($entry, $type, 'en', 'install');
        $up->assignSlug($taken, $type, 'en', 'setup');

        $up->assignSlug($entry, $type, 'en', 'installation');
        self::assertSame($entry, $up->findBySlug($type, 'en', 'installation'));
        self::assertNull($up->findBySlug($type, 'en', 'install'));
        $redirect = $this->container()->get(RedirectRepository::class)->findBySource($type, 'en', 'install');
        self::assertNotNull($redirect, 'the old URL still leads to the page');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/setup/');
        $up->assignSlug($entry, $type, 'en', 'setup');
    }
}
