<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Tests\Support\AppTestCase;
use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Authoring\PublishBlocked;
use Symfony\Component\HttpFoundation\Request;

/**
 * Proves thallo-workflow is cleanly disable-able: with thallo.workflow disabled, the boot
 * gate skips routes + listeners entirely (404s, no state mutations), and the publish gate
 * short-circuits so publish behaves as current core. Also guards the pack boundary: no
 * App\ references in packages/thallo-workflow/src.
 */
final class WorkflowRemovabilityTest extends AppTestCase
{
    private static ?ApplicationContext $disabledApp = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$disabledApp ??= self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.workflow' => false],
        ]);
    }

    private function hit(string $method, string $path): int
    {
        return (new Application(self::$disabledApp))->handle(
            Request::create($path, $method, [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ]),
        )->getStatusCode();
    }

    public function testWorkflowRoutesAbsentWhenDisabled(): void
    {
        self::assertSame(404, $this->hit('POST', '/v1/admin/workflow/entries/e-1/en/submit'));
        self::assertSame(404, $this->hit('GET', '/v1/admin/workflow/queue'));
        self::assertSame(404, $this->hit('GET', '/v1/admin/workflow/entries/e-1/en'));
    }

    public function testPublishUngatedWhenDisabled(): void
    {
        // The disabled-capability gate short-circuit is covered unit-style in
        // WorkflowPublishGateTest; here we prove the DISABLED APP's own container publishes
        // an unapproved draft without a PublishBlocked (behaves as current core).
        $c = self::$disabledApp->getContainer();
        $types = $c->get(\App\Content\Repositories\ContentTypeRepository::class);
        $entries = $c->get(\App\Content\Repositories\EntryRepository::class);
        $type = $types->create([
            'slug' => 'wfoff-post',
            'name' => 'WfOff',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $entry = $entries->createEntry($type, 'en', 1, 'nobypass0009');
        $entries->saveDraft($entry, 'en', ['title' => 'V1'], 1, 0, 'nobypass0009');

        try {
            $version = $c->get(\App\Content\Services\PublishService::class)
                ->publish($entry, 'en', 'nobypass0009');
            self::assertNotSame('', $version);
        } catch (PublishBlocked $e) {
            self::fail('publish must be UNGATED when thallo.workflow is disabled');
        }
    }

    public function testPackSourceHasNoAppReferences(): void
    {
        // Mirror scripts/check-pack-boundaries.php: a leading [^\w] catches bare \App\ FQCNs.
        $root = dirname(__DIR__, 3) . '/packages/thallo-workflow/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $checked = 0;
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression(
                '/(^|[^\\w])App\\\\/m',
                $src,
                "{$file->getPathname()} must not reference App\\ (pack boundary)",
            );
            $checked++;
        }
        self::assertGreaterThan(5, $checked, 'boundary sweep must actually see the pack sources');
    }
}
