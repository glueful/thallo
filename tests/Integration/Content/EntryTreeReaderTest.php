<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Contracts\Delivery\EntryTreeReader;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * A docs sidebar is every published page of a type, in its sections, in order — not the twelve
 * newest that `entries()` gives a blog block. The tree is grouped by an enum field in the ENUM'S
 * own order (the order the type's author wrote the sections in), sorted inside a group by a
 * number field and then by title, and carries only what navigation needs: never the bodies.
 */
final class EntryTreeReaderTest extends AppTestCase
{
    private function reader(): EntryTreeReader
    {
        return $this->container()->get(EntryTreeReader::class);
    }

    /** @param array<string,mixed> $fields */
    private function publish(string $typeUuid, string $slug, array $fields, bool $publish = true): string
    {
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entry = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', $fields, 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($entry, $typeUuid, 'en', $slug);
        if ($publish) {
            (new PublishService(
                $this->appContext(),
                $entries,
                new VersionRepository($this->connection()),
                $types,
                new FieldValidator($this->connection()),
                new ReferenceProjectionRepository($this->connection()),
            ))->publish($entry, 'en', 'user00000001');
        }
        return $entry;
    }

    private function handbook(bool $public = true): string
    {
        return (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'handbook' . ($public ? '' : 'x'),
            'name' => 'Handbook',
            'public_delivery' => $public,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'section', 'type' => 'enum', 'enum' => ['start', 'guides', 'reference']],
                ['name' => 'order', 'type' => 'number'],
                ['name' => 'summary', 'type' => 'string'],
                ['name' => 'body', 'type' => 'text', 'format' => 'plain'],
            ],
        ]);
    }

    public function testTheTreeIsEveryPublishedPageInItsSectionsInOrder(): void
    {
        $type = $this->handbook();
        $body = str_repeat('long body ', 50);
        $this->publish($type, 'theming', ['title' => 'Theming', 'section' => 'guides', 'order' => 2, 'body' => $body]);
        $install = $this->publish($type, 'install', [
            'title' => 'Install', 'section' => 'start', 'order' => 1, 'summary' => 'Get it running.', 'body' => $body,
        ]);
        $this->publish($type, 'blocks', ['title' => 'Blocks', 'section' => 'guides', 'order' => 1, 'body' => $body]);
        $this->publish($type, 'alpha', ['title' => 'Alpha', 'section' => 'guides', 'order' => 2, 'body' => $body]);
        $this->publish($type, 'loose', ['title' => 'Loose page', 'body' => $body]);
        $this->publish($type, 'draft', ['title' => 'Not out yet', 'section' => 'start', 'order' => 0], false);

        $tree = $this->reader()->tree('handbook', 'en', 'section', 'order');

        // Sections in the enum's order, and only the ones with pages; the unsectioned last.
        self::assertSame(['start', 'guides', ''], array_column($tree['groups'], 'key'));
        self::assertSame(['Start', 'Guides', ''], array_column($tree['groups'], 'label'));
        // By order, then by title; the draft is not there.
        self::assertSame(['Blocks', 'Alpha', 'Theming'], array_column($tree['groups'][1]['items'], 'title'));
        // The same pages, flat, in reading order — what previous and next walk.
        self::assertSame(
            ['Install', 'Blocks', 'Alpha', 'Theming', 'Loose page'],
            array_column($tree['items'], 'title'),
        );
        $first = $tree['items'][0];
        self::assertSame($install, $first['uuid']);
        self::assertStringEndsWith('/handbook/install', $first['href']);
        self::assertSame('install', $first['slug']);
        self::assertSame('start', $first['group']);
        self::assertSame('Get it running.', $first['summary']);
        // Navigation only: a sidebar must not carry every page's body.
        self::assertArrayNotHasKey('fields', $first);
        self::assertStringNotContainsString('long body', (string) json_encode($tree));

        // A page published, moved or retitled changes every sidebar: the type's own tag.
        self::assertContains('thallo:type:handbook', $tree['cache_tags']);
    }

    public function testATypeTheSiteDoesNotDeliverHasNoTree(): void
    {
        $empty = ['groups' => [], 'items' => [], 'cache_tags' => []];
        self::assertSame($empty, $this->reader()->tree('nope', 'en', 'section', 'order'));
        $type = $this->handbook(false);
        $this->publish($type, 'hidden', ['title' => 'Hidden', 'section' => 'start']);
        self::assertSame($empty, $this->reader()->tree('handbookx', 'en', 'section', 'order'));
    }

    public function testATypeWithoutTheGroupFieldIsOneUnnamedGroup(): void
    {
        $type = $this->handbook();
        $this->publish($type, 'b', ['title' => 'B']);
        $this->publish($type, 'a', ['title' => 'A']);
        $tree = $this->reader()->tree('handbook', 'en', 'chapter', 'weight');
        self::assertSame([''], array_column($tree['groups'], 'key'));
        self::assertSame(['A', 'B'], array_column($tree['items'], 'title'));
    }
}
