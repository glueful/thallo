<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\AppTestCase;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Helpers\Utils;
use Thallo\Collections\CollectionManager;
use Thallo\Collections\Schema\CollectionDefinition;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * End-to-end public collections data API tests.
 *
 * Drives real HTTP requests through the application kernel to verify the full
 * middleware chain: optional_api_key → collection_scope (default-deny) → controller.
 *
 * Covers: default-deny 403, scoped reads, create round-trip, api_key actor audit,
 * bulk all-or-nothing, referenced-row 409, uuid-keyed paths.
 */
final class PublicApiTest extends AppTestCase
{
    private const COL = 'testproducts';
    private const COL2 = 'testorders';

    private CollectionDefinition $def;
    private string $userUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purgeAuthFixtures();
        $this->cleanupCollections();

        $this->userUuid = $this->seedUser();

        $this->def = $this->manager()->create([
            'name'   => self::COL,
            'label'  => 'Test Products',
            'fields' => [
                ['name' => 'title', 'type' => 'collections.string', 'settings' => ['nullable' => false]],
                ['name' => 'price', 'type' => 'collections.integer', 'settings' => ['nullable' => true]],
            ],
        ], 'admin', 'setup');
    }

    protected function tearDown(): void
    {
        $this->cleanupCollections();
        $this->purgeAuthFixtures();

        parent::tearDown();
    }

    // ── Default-deny ─────────────────────────────────────────────────────────

    public function testReadWithoutApiKeyIsRejectedWith403(): void
    {
        $response = $this->appRequest('GET', '/v1/collections/' . self::COL);

        self::assertSame(403, $response->getStatusCode(), $response->getContent());
    }

    public function testReadWithWrongScopeIsRejectedWith403(): void
    {
        $key = $this->mintKey(['read:content']);

        $response = $this->appRequest('GET', '/v1/collections/' . self::COL, key: $key);

        self::assertSame(403, $response->getStatusCode(), $response->getContent());
    }

    public function testWriteScopeCannotRead(): void
    {
        $key = $this->mintKey(['collections.' . self::COL . '.write']);

        $response = $this->appRequest('GET', '/v1/collections/' . self::COL, key: $key);

        self::assertSame(403, $response->getStatusCode(), $response->getContent());
    }

    // ── List ─────────────────────────────────────────────────────────────────

    public function testListWithReadScopeReturns200(): void
    {
        $key = $this->mintKey(['collections.' . self::COL . '.read']);

        $response = $this->appRequest('GET', '/v1/collections/' . self::COL, key: $key);

        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $body = $this->json($response);
        self::assertArrayHasKey('data', $body);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function testCreateWithWriteScopeRoundTripsAndReturnsUuid(): void
    {
        $key = $this->mintKey(['collections.' . self::COL . '.write']);

        $response = $this->appRequest('POST', '/v1/collections/' . self::COL, key: $key, body: [
            'title' => 'Widget',
            'price' => 999,
        ]);

        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $data = $this->json($response)['data'];
        self::assertArrayHasKey('uuid', $data);
        self::assertNotEmpty($data['uuid']);
        self::assertSame('Widget', (string) $data['title']);
    }

    // ── API-key actor columns ─────────────────────────────────────────────────

    public function testRowCreatedViaApiKeyHasApiKeyActorColumns(): void
    {
        [$keyUuid, $plainKey] = $this->mintKeyWithUuid(['collections.' . self::COL . '.write']);

        $response = $this->appRequest('POST', '/v1/collections/' . self::COL, key: $plainKey, body: [
            'title' => 'Audited Widget',
        ]);

        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $data = $this->json($response)['data'];

        self::assertSame('api_key', (string) $data['created_by_type']);
        self::assertSame($keyUuid, (string) $data['created_by_id']);
    }

    // ── Bulk create ───────────────────────────────────────────────────────────

    public function testBulkCreateAllValidInsertsAllRows(): void
    {
        $key = $this->mintKey(['collections.' . self::COL . '.write']);

        $response = $this->appRequest('POST', '/v1/collections/' . self::COL . '/bulk', key: $key, body: [
            'rows' => [
                ['title' => 'Bulk A'],
                ['title' => 'Bulk B'],
                ['title' => 'Bulk C'],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $data = $this->json($response)['data'];
        self::assertCount(3, $data);

        $uuids = array_column($data, 'uuid');
        self::assertCount(3, array_filter($uuids), 'Every created row must have a uuid');
        self::assertCount(3, array_unique($uuids), 'All uuids must be distinct');
    }

    public function testBulkCreateWithOneInvalidRowRejects422AndZeroInserted(): void
    {
        $key = $this->mintKey(['collections.' . self::COL . '.write']);

        $response = $this->appRequest('POST', '/v1/collections/' . self::COL . '/bulk', key: $key, body: [
            'rows' => [
                ['title' => 'Valid Row'],
                ['price' => 10],           // missing required 'title'
                ['title' => 'Another OK'],
            ],
        ]);

        self::assertSame(422, $response->getStatusCode(), $response->getContent());
        $body = $this->json($response);
        self::assertArrayHasKey('error', $body);

        // Zero inserted: the physical table must be empty.
        $count = $this->connection()->table($this->def->tableName)->count();
        self::assertSame(0, $count, 'No rows must be inserted when bulk has any invalid row');
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function testShowByUuidReturnsRow(): void
    {
        $key = $this->mintKey(['collections.' . self::COL . '.write', 'collections.' . self::COL . '.read']);

        $created = $this->appRequest('POST', '/v1/collections/' . self::COL, key: $key, body: ['title' => 'Findable']);
        $uuid    = $this->json($created)['data']['uuid'];

        $response = $this->appRequest('GET', '/v1/collections/' . self::COL . '/' . $uuid, key: $key);

        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        self::assertSame('Findable', (string) $this->json($response)['data']['title']);
    }

    public function testShowWithUnknownUuidReturns404(): void
    {
        $key = $this->mintKey(['collections.' . self::COL . '.read']);

        $response = $this->appRequest('GET', '/v1/collections/' . self::COL . '/nonexistent-uuid-0000', key: $key);

        self::assertSame(404, $response->getStatusCode(), $response->getContent());
    }

    // ── UUID-keyed paths ──────────────────────────────────────────────────────

    public function testShowWithNumericIdReturns404(): void
    {
        // The show route accepts any string as {uuid}. A bare numeric string
        // ("1") should not match any row because rows are identified by uuid strings
        // (nanoid format), not by auto-increment integers.
        $key = $this->mintKey(['collections.' . self::COL . '.write', 'collections.' . self::COL . '.read']);
        $this->appRequest('POST', '/v1/collections/' . self::COL, key: $key, body: ['title' => 'Numeric Check']);

        $response = $this->appRequest('GET', '/v1/collections/' . self::COL . '/1', key: $key);

        self::assertSame(404, $response->getStatusCode(), $response->getContent());
    }

    // ── Delete referenced row → 409 ──────────────────────────────────────────

    public function testDeleteReferencedRowReturns409(): void
    {
        // Create an 'orders' collection with a relation to 'testproducts'.
        $this->manager()->create([
            'name'   => self::COL2,
            'label'  => 'Test Orders',
            'fields' => [
                [
                    'name'     => 'product',
                    'type'     => 'collections.relation',
                    'settings' => ['target' => 'collection:' . self::COL, 'nullable' => true, 'multi' => false],
                ],
            ],
        ], 'admin', 'setup');

        $key = $this->mintKey([
            'collections.' . self::COL . '.write',
            'collections.' . self::COL . '.delete',
            'collections.' . self::COL2 . '.write',
        ]);

        // Create a product row.
        $created     = $this->appRequest(
            'POST',
            '/v1/collections/' . self::COL,
            key: $key,
            body: ['title' => 'Ref Target'],
        );
        $productUuid = $this->json($created)['data']['uuid'];

        // Create an order referencing that product.
        $this->appRequest('POST', '/v1/collections/' . self::COL2, key: $key, body: ['product' => $productUuid]);

        // Delete the product — must be blocked (409).
        $response = $this->appRequest('DELETE', '/v1/collections/' . self::COL . '/' . $productUuid, key: $key);

        self::assertSame(409, $response->getStatusCode(), $response->getContent());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function manager(): CollectionManager
    {
        return $this->container()->get(CollectionManager::class);
    }

    private function seedUser(): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('users')->insert([
            'uuid'               => $uuid,
            'username'           => 'apitest_' . substr($uuid, 0, 6),
            'email'              => $uuid . '@example.test',
            'password'           => 'x',
            'status'             => 'active',
            'two_factor_enabled' => false,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }

    private function purgeAuthFixtures(): void
    {
        $this->connection()->table('api_keys')->where('id', '>', 0)->delete();
        $this->connection()->table('users')->where('id', '>', 0)->delete();
    }

    /** Mint a key; returns only the plaintext. */
    private function mintKey(array $scopes): string
    {
        $result = ApiKeyService::create($this->appContext(), [
            'user_uuid' => $this->userUuid,
            'name'      => 'collections-test-' . uniqid(),
            'scopes'    => $scopes,
        ]);
        return $result['plain'];
    }

    /**
     * Mint a key and return its uuid alongside the plaintext.
     *
     * @return array{0: string, 1: string}  [key_uuid, plain_key]
     */
    private function mintKeyWithUuid(array $scopes): array
    {
        $result = ApiKeyService::create($this->appContext(), [
            'user_uuid' => $this->userUuid,
            'name'      => 'collections-audit-' . uniqid(),
            'scopes'    => $scopes,
        ]);
        /** @var \Glueful\Auth\ApiKey\ApiKey $key */
        $key = $result['key'];
        return [(string) $key->uuid, $result['plain']];
    }

    private function tableNameFor(string $name): string
    {
        return CollectionManager::tableNameFor($name);
    }

    public function testPublicReadAllowsAnonymousAccessWhileWriteStaysScoped(): void
    {
        $this->manager()->create([
            'name'   => self::COL2,
            'label'  => 'Public Docs',
            'fields' => [
                ['name' => 'title', 'type' => 'collections.string', 'settings' => ['nullable' => false]],
            ],
            'access' => ['read' => 'public', 'write' => 'scoped', 'delete' => 'scoped'],
        ], 'admin', 'setup');

        // Anonymous read is allowed: the collection's read policy is public.
        $read = $this->appRequest('GET', '/v1/collections/' . self::COL2);
        self::assertSame(200, $read->getStatusCode(), (string) $read->getContent());

        // Anonymous write is still denied: write stays scoped and no credential was presented.
        $write = $this->appRequest('POST', '/v1/collections/' . self::COL2, body: ['title' => 'Hello']);
        self::assertSame(403, $write->getStatusCode(), (string) $write->getContent());
    }

    private function cleanupCollections(): void
    {
        $schema = $this->container()->get(SchemaBuilderInterface::class);
        $schema->reset();

        foreach ([self::COL, self::COL2] as $name) {
            $table = $this->tableNameFor($name);
            if ($schema->hasTable($table)) {
                $schema->dropTableIfExists($table);
            }
            $this->connection()->table('collection_definitions')
                ->where('name', $name)
                ->delete();
        }

        $this->connection()->table('collection_schema_changes')
            ->where('id', '>', 0)
            ->delete();
    }

    /**
     * Build an HTTP request and drive it through the real application kernel.
     *
     * @param array<string, mixed>|null $body  Payload (JSON-encoded if provided).
     */
    private function appRequest(
        string $method,
        string $path,
        ?string $key = null,
        ?array $body = null,
    ): HttpResponse {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
        ];

        if ($key !== null) {
            // X-API-Key alone exercises OptionalApiKeyAuthMiddleware; a second Bearer header would
            // be a latent dependency on middleware ordering (a JWT guard could eagerly reject it).
            $server['HTTP_X_API_KEY'] = $key;
        }

        $request = Request::create(
            $path,
            $method,
            [],
            [],
            [],
            $server,
            $body === null ? null : (string) json_encode($body),
        );

        return $this->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(HttpResponse $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true);
        return $decoded;
    }
}
