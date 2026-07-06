<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Preview\PreviewMinter;
use App\Content\Preview\PreviewToken;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\AppTestCase;
use Glueful\Cache\CacheStore;
use Thallo\Contracts\Delivery\PublicRouteResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * Preview-through-theme (preview spec §1–§3): the resolvePreview seam (kind content +
 * preview flag, fail-closed), the uncached /_preview/{token} route, headers, and the
 * banner. Uses the REAL token mechanism (PreviewMinter / PreviewToken).
 */
final class PreviewThemeTest extends AppTestCase
{
    use SeedsPublishedContent;

    protected function tearDown(): void
    {
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    private function resolver(): PublicRouteResolver
    {
        return $this->container()->get(PublicRouteResolver::class);
    }

    /** The signing key, derived EXACTLY like ResolvesPreviewKey (mint/read parity). */
    private function previewKey(): string
    {
        $key = (string) config($this->appContext(), 'app.key', '');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false) {
                $key = $decoded;
            }
        }
        return $key;
    }

    /** Seed a blog entry with a DRAFT (never published); returns its uuid. */
    private function seedDraftEntry(string $title = 'Draft title'): string
    {
        $types = new ContentTypeRepository($this->connection());
        if ($types->findBySlug('blog') === null) {
            $this->seedBilingualPublishedEntry(); // creates the type (and one published entry)
        }
        $typeUuid = (string) $types->findBySlug('blog')['uuid'];
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $uuid = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', ['title' => $title], 1, 0, 'user00000001');
        return $uuid;
    }

    public function testResolvePreviewReturnsContentKindWithPreviewFlag(): void
    {
        $entry = $this->seedDraftEntry('Unpublished words');
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');

        $r = $this->resolver()->resolvePreview($token);
        self::assertSame('content', $r['kind']);      // pinned: a content render…
        self::assertTrue($r['preview']);               // …with the preview flag
        self::assertSame('blog', $r['type']);
        self::assertSame('en', $r['locale']);
        self::assertSame('Unpublished words', $r['content']['fields']['title']);
        self::assertArrayNotHasKey('seo', $r['content']); // LIST shape — no seo (spec §2)
    }

    public function testResolvePreviewFailsClosed(): void
    {
        // Garbage token.
        self::assertSame('not_found', $this->resolver()->resolvePreview('garbage')['kind']);

        // Expired token (minted with a past expiry via PreviewToken directly).
        $entry = $this->seedDraftEntry();
        $expired = PreviewToken::mint($entry, 'en', null, time() - 60, $this->previewKey());
        self::assertSame('not_found', $this->resolver()->resolvePreview($expired)['kind']);

        // Valid token whose draft has since been deleted.
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');
        $this->connection()->table('entry_drafts')->where('entry_uuid', '=', $entry)->delete();
        self::assertSame('not_found', $this->resolver()->resolvePreview($token)['kind']);
    }

    public function testNonPreviewResultsCarryPreviewFalse(): void
    {
        $this->seedBilingualPublishedEntry();
        self::assertFalse($this->resolver()->resolvePath('/blog/hello')['preview']);
        self::assertFalse($this->resolver()->resolvePath('/no/such')['preview']);
    }

    public function testOldSchemaPreviewProjectsExactlyOnce(): void
    {
        // Review P1 regression: PreviewReader projects fields forward but reports the
        // ORIGINAL schema_version; the resolver must pin the synthesized row to the
        // CURRENT version or shape() re-runs the migration chain. The rename chain
        // re-uses a name (a→b, then c→a): double projection would clobber `b` with the
        // new `a`. Old draft (v1): {a: 'first', c: 'second'} → current (v3) must render
        // {b: 'first', a: 'second'} exactly once.
        $entry = $this->seedDraftEntry('seed'); // creates type + a draft we overwrite below
        $types = new ContentTypeRepository($this->connection());
        $typeUuid = (string) $types->findBySlug('blog')['uuid'];
        $this->connection()->table('content_types')->where('uuid', '=', $typeUuid)->update([
            'schema_version' => 3,
            'schema' => json_encode([
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'b', 'type' => 'string'],
                ['name' => 'a', 'type' => 'string'],
            ], JSON_THROW_ON_ERROR),
        ]);
        foreach (
            [
            ['schmigprev01', 1, 2, [['op' => 'rename', 'from' => 'a', 'to' => 'b']]],
            ['schmigprev02', 2, 3, [['op' => 'rename', 'from' => 'c', 'to' => 'a']]],
            ] as [$uuid, $from, $to, $ops]
        ) {
            $this->connection()->table('entry_schema_migrations')->insert([
                'uuid' => $uuid, 'content_type_uuid' => $typeUuid,
                'from_version' => $from, 'to_version' => $to,
                'ops' => json_encode($ops, JSON_THROW_ON_ERROR),
                'status' => 'completed', 'created_at' => '2026-06-01 00:00:00',
            ]);
        }
        // Rewrite the draft as an OLD-schema (v1) draft with both legacy field names.
        $this->connection()->table('entry_drafts')->where('entry_uuid', '=', $entry)->update([
            'schema_version' => 1,
            'fields' => json_encode(
                ['title' => 'Old draft', 'a' => 'first', 'c' => 'second'],
                JSON_THROW_ON_ERROR,
            ),
        ]);

        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');
        $r = $this->resolver()->resolvePreview($token);
        self::assertSame('content', $r['kind']);
        self::assertSame('first', $r['content']['fields']['b']);  // a→b, applied once
        self::assertSame('second', $r['content']['fields']['a']); // c→a, applied once
        self::assertArrayNotHasKey('c', $r['content']['fields']);
    }

    // ---- kernel: the /_preview/{token} route (preview spec §1, §3) -------------------

    public function testPreviewRouteRendersDraftUncachedWithHeadersAndBanner(): void
    {
        $entry = $this->seedDraftEntry('Only in preview');
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');

        $res = $this->handle(Request::create('/_preview/' . $token, 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();
        self::assertStringContainsString('Only in preview', $html);
        self::assertStringContainsString('preview-banner', $html); // default-theme banner
        self::assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        self::assertSame('noindex', $res->headers->get('X-Robots-Tag'));
        self::assertNull($res->headers->get('Cache-Tag'));         // no-store pages carry no tags

        // Structural cache bypass: NOTHING entered the page cache.
        self::assertSame(
            [],
            $this->container()->get(CacheStore::class)->getKeys('render:*'),
        );
    }

    public function testPreviewFailuresRenderThemed404WithNoStore(): void
    {
        $res = $this->handle(Request::create('/_preview/garbage-token', 'GET'));
        self::assertSame(404, $res->getStatusCode());
        self::assertStringContainsString('text/html', (string) $res->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        // The FIXED 404 body was not consulted or filled (spec §3).
        self::assertNull($this->container()->get(CacheStore::class)->get('render:default:404'));
    }

    public function testVersionPinnedTokenRendersThePinnedFieldsNotTheDraft(): void
    {
        // Publish v1 ("Hello"), then save a NEWER draft ("New draft words"); a token
        // pinned to v1's version uuid must render the pinned content.
        $entry = $this->seedBilingualPublishedEntry(); // published v1: title "Hello"
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entries->saveDraft($entry, 'en', ['title' => 'New draft words'], 1, 1, 'user00000001');
        $versionUuid = (string) $this->connection()->table('entry_versions')
            ->select(['uuid'])->where('entry_uuid', '=', $entry)
            ->where('locale', '=', 'en')->first()['uuid'];

        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en', $versionUuid);
        $res = $this->handle(Request::create('/_preview/' . $token, 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('<h1>Hello</h1>', (string) $res->getContent());
        self::assertStringNotContainsString('New draft words', (string) $res->getContent());
    }

    public function testNonPublicTypeDraftPreviewsFine(): void
    {
        // Token-is-authorization (spec §1): flip the type non-public; preview still works.
        $entry = $this->seedDraftEntry('Secret draft');
        $this->connection()->table('content_types')
            ->where('slug', '=', 'blog')->update(['public_delivery' => false]);
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');

        $res = $this->handle(Request::create('/_preview/' . $token, 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('Secret draft', (string) $res->getContent());
    }
}
