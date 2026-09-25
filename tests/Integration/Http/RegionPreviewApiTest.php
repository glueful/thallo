<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Http;

use Glueful\Validation\RequestDataHydrator;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\StarterBlockTypes;
use Thallo\Core\Content\Preview\RegionPreviewStore;
use Thallo\Core\Content\Preview\RegionPreviewToken;
use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Http\Controllers\RegionPreviewController;
use Thallo\Core\Http\DTOs\ApplyRegionsData;
use Thallo\Core\Http\DTOs\RegionSessionData;
use Thallo\Core\Settings\SettingsStore;
use Thallo\Core\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * The header & footer stage's sessions and applies (regions-stage spec §4.1, §4.3): a session pins
 * both saved regions as its baseline; an apply validates both regions and accepts them into that
 * session's own working copy, never another's.
 */
final class RegionPreviewApiTest extends AppTestCase
{
    use SeedsPublishedContent;

    private function controller(): RegionPreviewController
    {
        $repo = new BlockTypeRepository($this->connection());
        foreach (StarterBlockTypes::definitions() as $definition) {
            if ($repo->findBySlug($definition['slug']) === null) {
                $repo->create($definition);
            }
        }
        return $this->container()->get(RegionPreviewController::class);
    }

    /** @return array<string,mixed> */
    private function session(?string $page = null): array
    {
        $dto = (new RequestDataHydrator())->hydrate(RegionSessionData::class, ['page' => $page]);
        $resp = $this->controller()->session($dto);
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getContent());
        return json_decode((string) $resp->getContent(), true)['data'];
    }

    /** @param array<string,mixed> $body @return array{status: int, body: array<string,mixed>} */
    private function apply(array $body): array
    {
        $dto = (new RequestDataHydrator())->hydrate(ApplyRegionsData::class, $body);
        $resp = $this->controller()->apply($dto);
        $body = json_decode((string) $resp->getContent(), true) ?? [];
        return ['status' => $resp->getStatusCode(), 'body' => $body];
    }

    /** @return list<array<string,mixed>> */
    private function note(string $id, string $text): array
    {
        return [['id' => $id, 'type' => 'rich_text', 'data' => ['body' => "<p>{$text}</p>"]]];
    }

    /** @return array<string,mixed> */
    private function doc(
        string $headerText,
        string $headerId = 'hdr000000001',
        string $footerId = 'ftr000000001',
    ): array {
        return [
            'header' => ['blocks' => $this->note($headerId, $headerText), 'settings' => []],
            'footer' => ['blocks' => $this->note($footerId, 'footer'), 'settings' => []],
        ];
    }

    /** @return array<string,mixed> the token's claims */
    private function claims(string $token): array
    {
        $parts = explode('.', $token, 2);
        return json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true);
    }

    private function sessionId(string $token): string
    {
        return $this->claims($token)['s'];
    }

    private function store(): RegionPreviewStore
    {
        return $this->container()->get(RegionPreviewStore::class);
    }

    public function testASessionPinsBothSavedRegionsAndTheirVersions(): void
    {
        (new RegionRepository($this->connection()))
            ->save('header', $this->note('hdr000000001', 'saved'), ['sticky' => true], null);
        $data = $this->session();

        self::assertSame('/_preview/' . $data['token'], $data['theme_url']);
        self::assertSame(0, $data['regions']['header']['lock_version']);
        self::assertSame(['sticky' => true], $data['regions']['header']['settings']);
        self::assertNull($data['regions']['footer']['lock_version'], 'an absent row has no version');
        self::assertNull($data['epoch']);
        self::assertNull($data['revision']);
        self::assertArrayHasKey('style_generation', $data);
        // The baseline under the token's session is exactly what was returned.
        self::assertSame($data['regions'], $this->store()->baseline($this->sessionId($data['token'])));
    }

    public function testThePagePicksAPublishedPageOrFallsBackToTheHomepage(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $picked = $this->session($entry);
        self::assertSame($entry, $this->claims($picked['token'])['p']);

        // No homepage configured: an unknown page falls back to none.
        $none = $this->session('nosuchentry1');
        self::assertNull($this->claims($none['token'])['p']);

        // With a homepage, an unknown page falls back to it.
        $this->container()->get(SettingsStore::class)->putMany(['homepage_entry' => $entry]);
        $home = $this->session('nosuchentry1');
        self::assertSame($entry, $this->claims($home['token'])['p']);
    }

    public function testASessionReportsWhichRegionsThePageHides(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $this->connection()->table('entry_versions')
            ->where('entry_uuid', '=', $entry)->where('locale', '=', 'en')
            ->update(['fields' => json_encode(['title' => 'Hello', '_presentation' => ['header' => 'hidden']])]);
        $data = $this->session($entry);
        self::assertTrue($data['hidden']['header']);
        self::assertFalse($data['hidden']['footer']);
    }

    public function testAValidDocumentIsAcceptedAndAStalePairRefused(): void
    {
        $data = $this->session();
        $first = $this->apply(['token' => $data['token'], 'regions' => $this->doc('one')]);
        self::assertSame(200, $first['status'], json_encode($first['body']));
        self::assertSame(1, $first['body']['data']['revision']);
        self::assertArrayHasKey('style_generation', $first['body']['data']);

        $stale = $this->apply(['token' => $data['token'], 'regions' => $this->doc('two')]);
        self::assertSame(409, $stale['status']);
        self::assertSame('PREVIEW_REVISION_STALE', $stale['body']['error']['details']['code'] ?? null);
        self::assertSame(1, $stale['body']['error']['details']['current']['revision'] ?? null);
    }

    public function testAnInvalidRegionOrAnIdInBothRegionsIsRefused(): void
    {
        $data = $this->session();
        $heading = [
            'header' => [
                'blocks' => [['id' => 'hdr000000001', 'type' => 'heading', 'data' => ['text' => 'x']]],
                'settings' => [],
            ],
            'footer' => ['blocks' => [], 'settings' => []],
        ];
        $bad = $this->apply(['token' => $data['token'], 'regions' => $heading]);
        self::assertSame(422, $bad['status']);
        $errors = $bad['body']['error']['details'] ?? $bad['body']['errors'] ?? [];
        self::assertArrayHasKey('regions.header.blocks.0.type', $errors);

        $dup = $this->apply(['token' => $data['token'], 'regions' => $this->doc('x', 'samesame0001', 'samesame0001')]);
        self::assertSame(422, $dup['status']);
        self::assertStringContainsString('samesame0001', json_encode($dup['body']));
    }

    public function testAnEntryTokenOrAnExpiredSessionIsRefused(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $minter = $this->container()->get(\Thallo\Core\Content\Preview\PreviewMinter::class);
        $entryToken = $minter->mint($entry, 'en');
        self::assertSame(403, $this->apply(['token' => $entryToken, 'regions' => $this->doc('x')])['status']);

        // A valid token whose session records are gone.
        $signer = new class ($this->container()->get(\Glueful\Bootstrap\ApplicationContext::class)) {
            use \Thallo\Core\Content\Preview\ResolvesPreviewKey;

            public function __construct(private readonly \Glueful\Bootstrap\ApplicationContext $context)
            {
            }

            public function key(): string
            {
                return $this->previewKey($this->context);
            }
        };
        $orphan = RegionPreviewToken::mint('nosession000', null, 'en', time() + 600, $signer->key());
        self::assertSame(410, $this->apply(['token' => $orphan, 'regions' => $this->doc('x')])['status']);
    }

    public function testTwoSessionsDoNotTouchEachOther(): void
    {
        $a = $this->session();
        self::assertSame(200, $this->apply(['token' => $a['token'], 'regions' => $this->doc('from A')])['status']);
        $b = $this->session();
        self::assertSame(200, $this->apply(['token' => $b['token'], 'regions' => $this->doc('from B')])['status']);

        $snapA = $this->store()->snapshot($this->sessionId($a['token']));
        $snapB = $this->store()->snapshot($this->sessionId($b['token']));
        self::assertStringContainsString('from A', json_encode($snapA['regions']));
        self::assertStringContainsString('from B', json_encode($snapB['regions']));
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function routes(): array
    {
        return [
            ['POST', '/v1/admin/regions/preview/session', 'content_permission:content.view'],
            ['POST', '/v1/admin/regions/preview/apply', 'content_permission:content.manage'],
        ];
    }

    /** @dataProvider routes */
    public function testTheRoutesCarryAuthAndTheirPermission(string $method, string $path, string $permission): void
    {
        $route = $this->findRoute($method, $path);
        self::assertNotNull($route, "{$method} {$path} is not registered");
        self::assertContains('auth', $route['middleware']);
        self::assertContains($permission, $route['middleware']);
    }
}
