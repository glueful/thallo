<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Preview\LayoutPreviewStore;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Http\Controllers\LayoutPreviewController;
use Thallo\Core\Http\DTOs\ApplyLayoutData;
use Thallo\Core\Http\DTOs\LayoutSessionData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\Http\Controllers\RenderController;

/**
 * The layout stage (type layouts spec §5.2, §5.4): the session's layout in the frame around a
 * published sample — its own blocks selectable, the sample's not — or around a placeholder built in
 * memory when there is none or it has gone, and the removal page once the layout is removed.
 */
final class LayoutStageRenderTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private string $postType = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->postType = $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'post', 'name' => 'Posts', 'public_delivery' => true, 'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
    }

    /**
     * A published post, written as rows — so its body can hold what a save would refuse.
     *
     * @param list<array<string,mixed>> $body
     */
    private function publish(string $uuid, string $title, array $body): void
    {
        $db = $this->connection();
        $at = '2026-06-02 09:00:00';
        $version = 'v' . substr($uuid, 1);
        $db->table('entries')->insert(['uuid' => $uuid, 'content_type_uuid' => $this->postType, 'status' => 'active',
            'created_at' => $at, 'updated_at' => $at]);
        $db->table('entry_versions')->insert(['uuid' => $version, 'entry_uuid' => $uuid, 'locale' => 'en',
            'version' => 1, 'fields' => json_encode(['title' => $title, 'body' => $body], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => $at]);
        $db->table('entry_publications')->insert(['entry_uuid' => $uuid, 'locale' => 'en',
            'version_uuid' => $version, 'published_at' => $at]);
        (new RouteRepository($db))->assign($uuid, $this->postType, 'en', strtolower($title));
    }

    /** @return array<string,mixed> */
    private function session(?string $sample = null): array
    {
        $dto = (new RequestDataHydrator())->hydrate(
            LayoutSessionData::class,
            ['surface' => 'entry', 'target' => 'post', 'sample' => $sample],
        );
        $response = $this->container()->get(LayoutPreviewController::class)->session($dto);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true)['data'];
    }

    private function applyWorking(string $token): void
    {
        $dto = (new RequestDataHydrator())->hydrate(ApplyLayoutData::class, [
            'token' => $token,
            'layout' => ['blocks' => [
                ['id' => 'layhead00001', 'type' => 'heading', 'data' => ['text' => 'WORKING-MARKER'], 'settings' => []],
                ['id' => 'laybody00001', 'type' => 'entry_content', 'data' => ['field' => 'body'], 'settings' => []],
            ], 'settings' => []],
        ]);
        $response = $this->container()->get(LayoutPreviewController::class)->apply($dto);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    private function stage(string $token): string
    {
        $response = $this->container()->get(RenderController::class)->preview(
            Request::create("/_preview/{$token}?canvas=1", 'GET'),
            $token,
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        return (string) $response->getContent();
    }

    private static function sessionId(string $token): string
    {
        $parts = explode('.', $token, 2);
        return (string) json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true)['s'];
    }

    /** @return array<string,mixed> */
    private static function text(string $id, string $words): array
    {
        return ['id' => $id, 'type' => 'rich_text', 'data' => ['body' => "<p>{$words}</p>"], 'settings' => []];
    }

    public function testTheStageRendersTheWorkingCopyOverTheSample(): void
    {
        $this->publish('postsample01', 'Sampled', [self::text('bodytext0001', 'SAMPLE-WORDS')]);
        $session = $this->session();
        self::assertSame(['id' => 'postsample01', 'label' => 'Sampled'], $session['sample']);
        $this->applyWorking($session['token']);

        $html = $this->stage($session['token']);
        self::assertStringContainsString('data-thallo-canvas="layout"', $html);
        self::assertStringContainsString('WORKING-MARKER', $html);
        self::assertStringContainsString('data-thallo-block="layhead00001"', $html);
        self::assertStringContainsString('SAMPLE-WORDS', $html);
        // The sample's content is not the layout's: nothing in it is selectable.
        self::assertStringNotContainsString('data-thallo-block="bodytext0001"', $html);
        self::assertStringNotContainsString('data-thallo-placeholder', $html);
    }

    public function testNoSampleRendersThePlaceholderAndWritesNothing(): void
    {
        $entries = $this->connection()->table('entries')->count();
        $session = $this->session();
        self::assertTrue($session['placeholder']);

        $html = $this->stage($session['token']);
        self::assertStringContainsString('data-thallo-placeholder', $html);
        self::assertStringContainsString('No published posts yet — showing a placeholder', $html);
        self::assertStringContainsString('Sample post', $html);
        self::assertSame($entries, $this->connection()->table('entries')->count());
    }

    public function testAVanishedSampleFallsBackToThePlaceholder(): void
    {
        $this->publish('postgone0001', 'Vanishing', [self::text('bodytext0002', 'GONE-WORDS')]);
        $session = $this->session();
        $this->applyWorking($session['token']);
        $this->connection()->table('entry_publications')->where('entry_uuid', '=', 'postgone0001')->delete();

        $html = $this->stage($session['token']);
        self::assertStringContainsString('data-thallo-placeholder', $html);
        self::assertStringNotContainsString('GONE-WORDS', $html);
        self::assertStringContainsString('WORKING-MARKER', $html, 'the unsaved layout is untouched');
        self::assertNotNull(
            $this->container()->get(LayoutPreviewStore::class)->current(self::sessionId($session['token'])),
        );
    }

    public function testARetiredSessionRendersTheRemovalPage(): void
    {
        $session = $this->session();
        $this->container()->get(LayoutPreviewStore::class)->retire(self::sessionId($session['token']), time() + 600);

        $html = $this->stage($session['token']);
        self::assertStringContainsString('data-thallo-session-retired', $html);
        self::assertStringContainsString('This layout was removed.', $html);
    }

    public function testAMissingBlockTemplateInTheBodyLeavesTheLayoutEditable(): void
    {
        $this->publish('postbroken01', 'Broken', [
            ['id' => 'goneblock001', 'type' => 'gone_block', 'data' => [], 'settings' => []],
            self::text('bodytext0003', 'STILL-HERE'),
        ]);
        $session = $this->session();
        $this->applyWorking($session['token']);

        $html = $this->stage($session['token']);
        // The renderer's fallback for a missing template: a comment, or in debug a visible marker.
        self::assertMatchesRegularExpression(
            '~<!-- thallo: no template for block "gone_block" -->|Missing block template: blocks/gone_block\.twig~',
            $html,
        );
        self::assertStringContainsString('STILL-HERE', $html);
        self::assertStringContainsString('data-thallo-block="layhead00001"', $html);
    }
}
