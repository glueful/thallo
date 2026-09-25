<?php

/**
 * One region writer, in its own process, for the region lock's concurrency proof
 * (regions-stage plan R1/R4). Reads its input as JSON on stdin, boots the test application,
 * prints `{"ready": true, "pid": <backend pid>}`, then runs the writer — which blocks on the
 * region lock while the test holds it — and prints its result line.
 *
 *   persist     {slug, revision, blocks}: RegionsSource::persist on a DocumentRef built from the
 *               given (captured) revision.
 *   admin-save  {body}: the batch region save, through the controller (added in R4).
 */

declare(strict_types=1);

use Glueful\Database\Connection;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Blocks\Sources\RegionsSource;
use Thallo\Core\Content\Schema\ContentTypeSchema;
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
    case 'persist':
        $source = new RegionsSource($db);
        $ref = new DocumentRef(
            RegionsSource::ID,
            (string) $input['slug'],
            null,
            (string) $input['revision'],
            ContentTypeSchema::fromArray([['name' => 'blocks', 'type' => 'blocks']]),
            [],
        );
        $persisted = $source->persist($ref, ['blocks' => $input['blocks']]);
        fwrite(STDOUT, json_encode(['persisted' => $persisted]) . "\n");
        break;
    default:
        fwrite(STDERR, "unknown writer path: {$path}\n");
        exit(2);
}
