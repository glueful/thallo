<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Cache\CacheStore;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Custom palette spec §7: a site that sets no palette key is served byte-identical HTML to the
 * page captured before the palette existed, once the compiled-stylesheet URL (which changes with
 * the compiler version), file-time cache busters and per-run values (entry ids, nonces) are
 * normalised.
 */
final class PrePaletteRenderSnapshotTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private const FIXTURE = __DIR__ . '/../../fixtures/palette/pre-palette-page.html';

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    protected function tearDown(): void
    {
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        $this->container()->get(\Thallo\Seo\Cache\SitemapCache::class)->forgetAll();
        parent::tearDown();
    }

    private function publish(): string
    {
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $type = $types->create([
            'slug' => 'snap',
            'name' => 'Snap',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $entry = $entries->createEntry($type, 'en', 1, 'user00000001');
        $tok = static fn (string $v): array => ['type' => 'token', 'value' => $v];
        $entries->saveDraft($entry, 'en', [
            'title' => 'Snapshot',
            'body' => [
                [
                    'id' => 'head00000001', 'type' => 'heading',
                    'data' => ['text' => 'Heading', 'level' => 'h2'],
                    'settings' => ['style' => ['colors' => ['text' => $tok('color.accent')]]],
                ],
                [
                    'id' => 'cont00000001', 'type' => 'container',
                    'data' => ['element' => 'div', 'content' => [[
                        'id' => 'butn00000001', 'type' => 'button',
                        'data' => ['label' => 'Go', 'url' => '/'],
                        'settings' => ['style' => [
                            'colors' => ['text' => $tok('color.accent-contrast'), 'surface' => $tok('color.accent')],
                            'hover' => ['colors' => ['surface' => $tok('color.text')]],
                        ]],
                    ]]],
                    'settings' => ['style' => ['colors' => ['surface' => $tok('color.surface-2')]]],
                ],
            ],
        ], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($entry, $type, 'en', 'page');
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            $types,
            new FieldValidator($this->connection()),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, 'en', 'user00000001');
        return $entry;
    }

    public static function normalise(string $html, string $entry): string
    {
        $html = str_replace($entry, '{{ENTRY}}', $html);
        // The compiled style artifact: its name is its content hash, which a compiler version changes.
        $html = (string) preg_replace('#/theme-assets/settings-[0-9a-f]+\.css#', '{{STYLESHEET}}', $html);
        // File-time cache busters (`v=<mtime>`) differ per checkout; content hashes are kept.
        $html = (string) preg_replace('/([?&](?:amp;)?v=)\d{9,}/', '$1{{MTIME}}', $html);
        return (string) preg_replace('/nonce="[^"]*"/', 'nonce="{{NONCE}}"', $html);
    }

    public function testASiteWithNoPaletteIsServedTheSameHtml(): void
    {
        $entry = $this->publish();
        $res = $this->handle(Request::create('/snap/page', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $html = self::normalise((string) $res->getContent(), $entry);
        if (!is_file(self::FIXTURE)) {
            file_put_contents(self::FIXTURE, $html);
            self::markTestIncomplete('captured');
        }
        self::assertSame((string) file_get_contents(self::FIXTURE), $html);
    }
}
