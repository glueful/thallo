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

/**
 * The default entry template renders a rich-text `body` as sanitised HTML, and a plain-text one
 * escaped: it is the field's format, not its name, that says which.
 */
final class RichTextBodyRenderTest extends AppTestCase
{
    protected function tearDown(): void
    {
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    public function testARichTextBodyRendersAsSanitisedHtml(): void
    {
        $this->publish('rich-note', 'rich', '<p>Hello <strong>world</strong></p><script>alert(1)</script>');

        $html = $this->page('/rich-note/one');

        self::assertStringContainsString('<strong>world</strong>', $html);
        self::assertStringNotContainsString('&lt;strong&gt;', $html);
        self::assertStringNotContainsString('alert(1)', $html);
    }

    public function testAPlainTextBodyStaysEscaped(): void
    {
        $this->publish('plain-note', 'plain', 'Use <b> for bold');

        $html = $this->page('/plain-note/one');

        self::assertStringContainsString('Use &lt;b&gt; for bold', $html);
    }

    private function publish(string $slug, string $format, string $body): void
    {
        $types = new ContentTypeRepository($this->connection());
        $type = $types->create([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'text', 'format' => $format],
            ],
        ]);
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $uuid = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', ['title' => 'One', 'body' => $body], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($uuid, $type, 'en', 'one');
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            $types,
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($uuid, 'en', 'user00000001');
    }

    private function page(string $path): string
    {
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        $res = $this->handle(Request::create($path, 'GET'));
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());

        return (string) $res->getContent();
    }
}
