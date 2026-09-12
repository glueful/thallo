<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Setup;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Routing\RouteManifest;
use Thallo\Core\Setup\ApiReferencePublisher;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * The framework's docs route serves two files from `documentation.paths.output`: the OpenAPI
 * document and the reference UI. The development repository tracks them (`composer docs:openapi`);
 * an install from the template has neither, so `/api-docs` answered 404 on every fresh install.
 * Provision now generates both from the install's live routes, so the reference reflects that
 * install's enabled capabilities and the operator's own routes.
 */
final class ApiReferencePublisherTest extends AppTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/thallo-api-reference-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/openapi.json');
        @unlink($this->dir . '/index.html');
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testTheSpecAndTheUiAreWrittenWhereTheDocsRouteServesThem(): void
    {
        $written = (new ApiReferencePublisher())->publish($this->contextWritingTo($this->dir));

        self::assertSame($this->dir . '/openapi.json', $written['spec']);
        self::assertSame($this->dir . '/index.html', $written['ui']);

        $spec = json_decode((string) file_get_contents($written['spec']), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey(
            '/admin/config',
            $spec['paths'],
            'the document is generated from the live routes',
        );
        self::assertArrayHasKey('/v1/admin/entries', $spec['paths']);

        $ui = (string) file_get_contents($written['ui']);
        self::assertStringContainsString(
            '/api-docs/openapi.json',
            $ui,
            'the UI loads the document from the docs route',
        );
    }

    /**
     * A context over the shared container whose documentation paths point at a scratch directory:
     * the generator reads its output paths from config, and the shared, booted context cannot be
     * overridden. The shared router already holds every route, so the manifest guard is pinned:
     * an earlier secondary boot in this process may have reset it, and re-running the manifest
     * against a populated router would register the framework's routes twice.
     */
    private function contextWritingTo(string $dir): ApplicationContext
    {
        $root = dirname(__DIR__, 3);
        $context = new ApplicationContext($root, 'testing');
        $context->setConfigLoader(new ConfigurationLoader($root, 'testing'));
        $context->overrideConfig('documentation.paths.output', $dir);
        $context->overrideConfig('documentation.paths.openapi', $dir . '/openapi.json');
        $context->setContainer($this->container());

        (new \ReflectionProperty(RouteManifest::class, 'loaded'))->setValue(null, true);

        return $context;
    }
}
