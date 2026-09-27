<?php

/**
 * One layout writer, in its own process, for the layout lock's concurrency proofs (type layouts
 * plan L1, L8). Reads its input as JSON on stdin, boots the test application, prints
 * `{"ready": true, "pid": <backend pid>}`, then runs the writer — which blocks on the layout lock
 * while the test holds it — and prints its result line.
 *
 *   save  {surface, target, expected}: LayoutRepository::saveExpected inside LayoutWriteLock::within.
 *   save-binding  {target, field}: a first save, through LayoutSaver, of a layout whose Entry excerpt
 *                 binds `field` — it waits on the type's lock, then validates against the schema.
 */

declare(strict_types=1);

use Glueful\Database\Connection;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutSaver;
use Thallo\Core\Content\Preview\LayoutPreviewToken;
use Thallo\Core\Content\Preview\ResolvesPreviewKey;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Content\Layouts\LayoutVersionConflict;
use Thallo\Core\Content\Layouts\LayoutWriteLock;
use Thallo\Core\Tests\Support\TestApplication;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$path = $argv[1] ?? '';
$input = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);

$app = TestApplication::instance();
$container = $app->getContainer();
/** @var Connection $db */
$db = $container->get(Connection::class);
$pid = (int) $db->getPDO()->query('SELECT pg_backend_pid()')->fetchColumn();
fwrite(STDOUT, json_encode(['ready' => true, 'pid' => $pid]) . "\n");
fflush(STDOUT);

switch ($path) {
    case 'save':
        $lock = new LayoutWriteLock($db);
        $repo = new LayoutRepository($db, $lock);
        try {
            $version = $lock->within(
                (string) $input['surface'],
                (string) $input['target'],
                fn (): int => $repo->saveExpected(
                    (string) $input['surface'],
                    (string) $input['target'],
                    [['type' => 'heading', 'data' => ['text' => 'child'], 'settings' => []]],
                    [],
                    (int) $input['expected'],
                    null,
                ),
            );
            fwrite(STDOUT, json_encode(['version' => $version]) . "\n");
        } catch (LayoutVersionConflict $e) {
            fwrite(STDOUT, json_encode(['conflict' => true, 'current' => $e->current]) . "\n");
        }
        break;
    case 'save-binding':
        $keys = new class () {
            use ResolvesPreviewKey;

            public function of(\Glueful\Bootstrap\ApplicationContext $context): string
            {
                return $this->previewKey($context);
            }
        };
        $key = $keys->of($container->get(\Glueful\Bootstrap\ApplicationContext::class));
        $target = (string) $input['target'];
        $token = LayoutPreviewToken::mint('childsession01', 'entry', $target, null, 'en', time() + 600, $key);
        $blocks = [
            ['id' => 'laybody00001', 'type' => 'entry_content', 'data' => ['field' => 'body'], 'settings' => []],
            ['id' => 'layexcer0001', 'type' => 'entry_excerpt', 'data' => ['field' => (string) $input['field']],
                'settings' => []],
        ];
        try {
            $saved = $container->get(LayoutSaver::class)
                ->save(LayoutPreviewToken::verify($token, $key, time()), $blocks, [], 0, null, null);
            fwrite(STDOUT, json_encode(['version' => $saved['layout']['lock_version']]) . "\n");
        } catch (ValidationException $e) {
            fwrite(STDOUT, json_encode(['invalid' => $e->errors()]) . "\n");
        } catch (LayoutVersionConflict $e) {
            fwrite(STDOUT, json_encode(['conflict' => true, 'current' => $e->current]) . "\n");
        }
        break;
    default:
        fwrite(STDERR, "unknown writer path: {$path}\n");
        exit(2);
}
