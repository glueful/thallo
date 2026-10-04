<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Http;

use Thallo\Core\Content\Fields\FieldOptionsController;
use Thallo\Core\Content\Http\RequirePermission;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Rbac\GrantsPermissions;

/**
 * Block fields with server-provided choices (search block spec §3.9): `GET /v1/admin/field-options/
 * {source}` resolves only registered sources, in the server's workspace, under the source's own
 * permission — so an author who can edit pages gets the Search block's scope picker without the
 * right to manage search.
 */
final class FieldOptionsApiTest extends AppTestCase
{
    use GrantsPermissions;

    protected function tearDown(): void
    {
        $this->scrubGrants();
        parent::tearDown();
    }

    public function testAnAuthorWithEditButNotManageLoadsTheScopes(): void
    {
        $author = $this->userWith('test_author_fo', ['content.edit']);
        $request = $this->requestAs($author);

        self::assertTrue($this->routeAllows($request, 'content.edit'), 'the route lets an author through');
        self::assertFalse($this->routeAllows($request, 'content.manage'), 'who cannot manage settings');

        $res = $this->controller()->show($request, 'thallo-search.scopes');
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $options = json_decode((string) $res->getContent(), true)['data']['options'];
        $byValue = array_column($options, null, 'value');
        self::assertSame('All results', $byValue['']['label']);
        self::assertArrayHasKey('entries', $byValue);
        self::assertArrayHasKey('products', $byValue);
        self::assertFalse($byValue['products']['available'], 'search is off in the default test boot');
        self::assertNotNull($byValue['products']['reason']);
    }

    public function testAnUnknownSourceIs404AndNoIdentityIs403(): void
    {
        $author = $this->userWith('test_author_fo2', ['content.edit']);
        self::assertSame(404, $this->controller()->show($this->requestAs($author), 'nope.source')->getStatusCode());
        self::assertSame(
            403,
            $this->controller()->show($this->requestAs(null), 'thallo-search.scopes')->getStatusCode(),
        );
    }

    public function testWithSearchOnTheAvailableKindsSaySo(): void
    {
        $on = self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.search' => true]]);
        $author = $this->userWith('test_author_fo3', ['content.edit']);
        $res = $on->getContainer()->get(FieldOptionsController::class)->show(
            $this->requestAs($author),
            'thallo-search.scopes',
        );
        $byValue = array_column(json_decode((string) $res->getContent(), true)['data']['options'], null, 'value');
        self::assertTrue($byValue['entries']['available']);
        self::assertTrue($byValue['products']['available'], 'commerce is on in the test baseline');
    }

    private function controller(): FieldOptionsController
    {
        return $this->container()->get(FieldOptionsController::class);
    }

    private function routeAllows(\Symfony\Component\HttpFoundation\Request $request, string $permission): bool
    {
        $reached = false;
        (new RequirePermission($this->appContext()))->handle($request, function () use (&$reached) {
            $reached = true;
            return \Glueful\Http\Response::success([]);
        }, $permission);
        return $reached;
    }
}
