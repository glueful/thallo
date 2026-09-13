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
 * The update notice's read surface. Deliberately NOT on the unauthenticated /admin/config
 * (DISTRIBUTION.md decision 11, amended): an anonymous endpoint that reveals the installed version
 * is a fingerprint. Any signed-in admin user reads it (the version shows in the user menu).
 */
final class UpdateStatusApiTest extends AppTestCase
{
    private const PATH = '/v1/admin/update-status';

    public function testTheRouteIsAuthenticatedButNotOperatorOnly(): void
    {
        $route = $this->findRoute('GET', self::PATH);

        self::assertNotNull($route, 'GET ' . self::PATH . ' must be registered');
        self::assertContains('auth', $route['middleware']);
        self::assertNotContains(
            'content_permission:system.access',
            $route['middleware'],
            'every signed-in admin user may see which Thallo they are on',
        );
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
