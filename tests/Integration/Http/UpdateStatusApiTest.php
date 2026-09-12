<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Http;

use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Core\Http\Controllers\UpdateStatusController;
use Thallo\Core\Http\DTOs\Responses\UpdateStatusData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ScriptedReleaseFeed;
use Thallo\Core\Updates\UpdateChecker;

/**
 * The update notice's read surface for administrators. Deliberately NOT on the unauthenticated
 * /admin/config (DISTRIBUTION.md decision 11, amended): an anonymous endpoint that reveals the
 * installed version is a fingerprint. Operators with system.access read it; nobody else.
 */
final class UpdateStatusApiTest extends AppTestCase
{
    private const PATH = '/v1/admin/update-status';

    public function testTheRouteIsAuthenticatedAndOperatorOnly(): void
    {
        $route = $this->findRoute('GET', self::PATH);

        self::assertNotNull($route, 'GET ' . self::PATH . ' must be registered');
        self::assertContains('auth', $route['middleware']);
        self::assertContains('content_permission:system.access', $route['middleware']);
    }

    public function testAnonymousRequestsAreRejected(): void
    {
        $response = $this->handle($this->jsonRequest('GET', self::PATH));

        self::assertSame(401, $response->getStatusCode());
    }

    public function testItReportsTheStatusInTheDocumentedShape(): void
    {
        $this->container()->get(SystemChannel::class)->put(UpdateChecker::FLAG_LATEST, '1.0.0-beta.22');
        $checker = new UpdateChecker(
            new ScriptedReleaseFeed([]),
            $this->container()->get(SystemChannel::class),
            ['enabled' => true, 'package' => 'glueful/thallo-core', 'notes_url' => 'https://example.test/notes'],
            '1.0.0-beta.21',
            false,
        );

        $body = json_decode((string) (new UpdateStatusController($checker))->show()->getContent(), true);
        $update = $body['data']['update'];

        self::assertDataMatchesDtoShape($update, UpdateStatusData::class);
        self::assertSame('1.0.0-beta.21', $update['current']);
        self::assertSame('1.0.0-beta.22', $update['latest']);
        self::assertTrue($update['available']);
        self::assertSame('https://example.test/notes', $update['notesUrl']);
    }
}
