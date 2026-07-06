<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\EngineBlockEditableFieldResolver;
use App\Content\Preview\PreviewMinter;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\RouteRepository;
use App\Tests\Support\AppTestCase;
use Thallo\Render\Http\Controllers\RenderController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Edit-in-place spec §2: safe_html marks prose rich-field output in ANNOTATED
 * renders only — both data attributes present, never in live renders, never
 * for non-prose blocks. The resolver mirrors the client prose convention.
 */
final class EditInPlaceMarkingTest extends AppTestCase
{
    private string $type;

    protected function tearDown(): void
    {
        $this->container()->get(\Glueful\Cache\CacheStore::class)->deletePattern('render:*');
        $this->container()->get(\Thallo\Seo\Cache\SitemapCache::class)->forgetAll();
        parent::tearDown();
    }

    public function testResolverMirrorsTheProseConvention(): void
    {
        $repo = new BlockTypeRepository($this->connection());
        $repo->create([
            'slug' => 'rich_text',
            'label' => 'Text',
            'schema' => [['name' => 'body', 'type' => 'text', 'format' => 'rich']],
        ]);
        $repo->create([
            'slug' => 'promo', // two fields -> NOT prose
            'label' => 'Promo',
            'schema' => [
                ['name' => 'body', 'type' => 'text', 'format' => 'rich'],
                ['name' => 'title', 'type' => 'string'],
            ],
        ]);
        $repo->create([
            'slug' => 'quote', // single field but plain text -> NOT prose
            'label' => 'Quote',
            'schema' => [['name' => 'text', 'type' => 'text']],
        ]);
        $resolver = new EngineBlockEditableFieldResolver($repo);
        self::assertSame('body', $resolver->editableRichField('rich_text'));
        self::assertNull($resolver->editableRichField('promo'));
        self::assertNull($resolver->editableRichField('quote'));
        self::assertNull($resolver->editableRichField('missing'));
    }

    public function testPreviewMarksProseRegionsAndLiveDoesNot(): void
    {
        // rich_text matches the starter theme's blocks/rich_text.twig, which
        // emits the field through safe_html — the marking seam under test.
        (new BlockTypeRepository($this->connection()))->create([
            'slug' => 'rich_text',
            'label' => 'Text',
            'schema' => [['name' => 'body', 'type' => 'text', 'format' => 'rich']],
        ]);
        $types = new ContentTypeRepository($this->connection());
        $this->type = $types->create([
            'slug' => 'page',
            'name' => 'Page',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entry = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', ['title' => 'S', 'body' => [
            ['id' => 'proseblk0001', 'type' => 'rich_text', 'data' => ['body' => '<p>Hello prose</p>']],
        ]], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($entry, $this->type, 'en', 'eip-page');

        // ANNOTATED (direct token) render: region wrapper with BOTH attributes.
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');
        $preview = (string) $this->container()->get(RenderController::class)->preview(
            Request::create("/_preview/{$token}", 'GET'),
            $token,
        )->getContent();
        self::assertStringContainsString('Hello prose', $preview);
        self::assertStringContainsString('class="thallo-edit-region"', $preview);
        self::assertStringContainsString('data-thallo-edit-block="proseblk0001"', $preview);
        self::assertStringContainsString('data-thallo-edit-field="body"', $preview);

        // LIVE render: publish, request the public path, assert NO marking.
        $version = (new \App\Content\Services\PublishService(
            $this->appContext(),
            $entries,
            new \App\Content\Repositories\VersionRepository($this->connection()),
            $types,
            new \App\Content\Validation\FieldValidator(
                $this->connection(),
                $this->appContext(),
                new BlockTypeRepository($this->connection()),
            ),
            new \App\Content\Repositories\ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, 'en', 'user00000001');
        self::assertNotSame('', $version);
        $live = $this->handle(Request::create('/page/eip-page', 'GET'));
        $liveHtml = (string) $live->getContent();
        self::assertStringContainsString('Hello prose', $liveHtml);
        self::assertStringNotContainsString('thallo-edit-region', $liveHtml);
        self::assertStringNotContainsString('data-thallo-edit-block', $liveHtml);
    }

    public function testNestedProseInsideAContainerGetsItsOwnFrame(): void
    {
        // The frame STACK under test: section.twig calls blocks(data.content),
        // so the nested rich_text renders inside the parent's frame scope —
        // its region must carry the NESTED id, and the section itself (not
        // prose) must never be marked.
        $repo = new BlockTypeRepository($this->connection());
        $repo->create([
            'slug' => 'rich_text',
            'label' => 'Text',
            'schema' => [['name' => 'body', 'type' => 'text', 'format' => 'rich']],
        ]);
        $repo->create([
            'slug' => 'section',
            'label' => 'Section',
            'schema' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'content', 'type' => 'blocks'],
            ],
        ]);
        $types = new ContentTypeRepository($this->connection());
        $this->type = $types->create([
            'slug' => 'page',
            'name' => 'Page',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entry = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', ['title' => 'S', 'body' => [
            ['id' => 'sectionb0001', 'type' => 'section', 'data' => [
                'title' => 'Wrap',
                'content' => [
                    ['id' => 'nestedpr0001', 'type' => 'rich_text', 'data' => ['body' => '<p>Nested prose</p>']],
                ],
            ]],
        ]], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($entry, $this->type, 'en', 'eip-nested');

        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');
        $html = (string) $this->container()->get(RenderController::class)->preview(
            Request::create("/_preview/{$token}", 'GET'),
            $token,
        )->getContent();
        self::assertStringContainsString('data-thallo-edit-block="nestedpr0001"', $html);
        // The section's own prose marking never appears (safe_html, non-prose)
        // — but with editable_text adoption its TITLE region legitimately may;
        // assert the absence of a safe_html-style rich region specifically by
        // checking no marker carries the section id with the rich field name.
        self::assertStringNotContainsString('data-thallo-edit-block="sectionb0001" data-thallo-edit-field="body"', $html);
    }

    /** Seed a page whose body holds one `hero` block with the given data. */
    private function seedHeroPage(string $slug, array $heroData): string
    {
        $blockTypes = new BlockTypeRepository($this->connection());
        // The §2b hero shape — the shared theme template reads these fields.
        $blockTypes->create([
            'slug' => 'hero',
            'label' => 'Hero',
            'schema' => [
                ['name' => 'headline', 'type' => 'string'],
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'description', 'type' => 'text'],
                ['name' => 'links', 'type' => 'blocks'],
                ['name' => 'image', 'type' => 'asset'], // Lemma schema type is asset, not media
                ['name' => 'orientation', 'type' => 'enum', 'enum' => ['vertical', 'horizontal']],
                ['name' => 'reverse', 'type' => 'boolean'],
            ],
        ]);
        $blockTypes->create([
            'slug' => 'button',
            'label' => 'Button',
            'schema' => [
                ['name' => 'label', 'type' => 'string'],
                ['name' => 'url', 'type' => 'string'],
            ],
        ]);
        $types = new ContentTypeRepository($this->connection());
        $this->type = $types->create([
            'slug' => 'page',
            'name' => 'Page',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entry = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', ['title' => 'S', 'body' => [
            ['id' => 'heroblok0001', 'type' => 'hero', 'data' => $heroData],
        ]], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($entry, $this->type, 'en', $slug);
        return $entry;
    }

    public function testEditableTextMarksAnnotatedRendersAndEscapesTheValue(): void
    {
        $entry = $this->seedHeroPage('et-page', [
            'title' => 'Big <b>launch</b> "day"',
            'links' => [['id' => 'herobtn00001', 'type' => 'button',
                'data' => ['label' => 'Go', 'url' => '/x']]],
        ]);
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');
        $html = (string) $this->container()->get(RenderController::class)->preview(
            Request::create("/_preview/{$token}", 'GET'),
            $token,
        )->getContent();

        // Marked span with BOTH attributes; the VALUE is filter-escaped.
        self::assertStringContainsString(
            '<span class="thallo-edit-region" data-thallo-edit-block="heroblok0001"'
                . ' data-thallo-edit-field="title">Big &lt;b&gt;launch&lt;/b&gt; &quot;day&quot;</span>',
            $html,
        );
        // The button label inside the <a> is marked too (interactive-element
        // pin), against the BUTTON child block's own id.
        self::assertStringContainsString(
            'data-thallo-edit-block="herobtn00001" data-thallo-edit-field="label">Go</span>',
            $html,
        );
    }

    public function testEditableTextLiveRendersAreByteIdenticalToPlainOutput(): void
    {
        $entry = $this->seedHeroPage('et-live', ['title' => 'A & B']);
        // Publish so the live route serves it.
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        (new \App\Content\Services\PublishService(
            $this->appContext(),
            $entries,
            new \App\Content\Repositories\VersionRepository($this->connection()),
            $types,
            new \App\Content\Validation\FieldValidator(
                $this->connection(),
                $this->appContext(),
                new BlockTypeRepository($this->connection()),
            ),
            new \App\Content\Repositories\ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, 'en', 'user00000001');

        $live = (string) $this->handle(Request::create('/page/et-live', 'GET'))->getContent();
        self::assertStringContainsString('A &amp; B', $live);
        self::assertStringNotContainsString('thallo-edit-region', $live);
        self::assertStringNotContainsString('data-thallo-edit-field', $live);
    }

    public function testEditableTextEmptyAndNonStringValues(): void
    {
        // title '' -> EMPTY span in annotated renders (clickable blank, spec §0).
        $entry = $this->seedHeroPage('et-empty', ['title' => '']);
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');
        $html = (string) $this->container()->get(RenderController::class)->preview(
            Request::create("/_preview/{$token}", 'GET'),
            $token,
        )->getContent();
        self::assertStringContainsString('data-thallo-edit-field="title"></span>', $html);

        // Direct filter calls: non-string -> '', and NO frame -> escaped value only
        // even with annotations on.
        $ext = $this->container()->get(\Thallo\Render\RenderContextExtension::class);
        $ext->setBlockAnnotations(true);
        $ext->resetBlockFrames();
        self::assertSame('x &lt;y&gt;', $ext->editableText('x <y>', 'f'));
        self::assertSame('', $ext->editableText(['array'], 'f'));
        self::assertSame('', $ext->editableText(null, 'f'));
        $ext->setBlockAnnotations(false);
    }
}
