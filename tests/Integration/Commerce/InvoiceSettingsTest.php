<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Content\Media\TenantBlobPolicy;
use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Glueful\Http\Response;
use Glueful\Uploader\Contracts\BlobAccessContext;
use Glueful\Uploader\Contracts\BlobAccessPolicy;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Http\CommerceSettingsController;
use Thallo\Commerce\Settings\CommerceSettingsStore;
use Thallo\Commerce\Settings\InvoiceLogoResolver;
use Thallo\Contracts\Delivery\MediaUrlResolver;
use Thallo\Contracts\Tenancy\WriteBarrier;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Orders-invoices-receipts spec, Task 6: the six `commerce.invoice.*` settings keys, mounted on
 * the existing `GET/PUT /v1/admin/commerce/settings` endpoint (CommerceSettingsEndpointTest owns
 * the other keys). The behaviours that carry the weight here: boolean canonicalization
 * (real bool in/out, '1'/'0' on disk), the footer's REFUSE-never-strip '<' rule, the paper preset
 * enum, and — the one genuinely new authority — InvoiceLogoResolver's ownership+servability gate,
 * exercised both through the container-bound wiring (tenancy off, the default test posture) and
 * through a deliberately tenant-scoped TenantBlobPolicy for the cross-tenant refusal case.
 */
final class InvoiceSettingsTest extends AppTestCase
{
    private const UPLOADER = 'user00000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->table('media_assets')->where('id', '>', 0)->forceDelete();
    }

    protected function tearDown(): void
    {
        $this->connection()->table('media_assets')->where('id', '>', 0)->forceDelete();
        $this->container()->get(SystemFlags::class)->forget('tenancy.enabled');
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Defaults + round trips
    // -----------------------------------------------------------------

    public function testDefaultsOnAnUnsetInstall(): void
    {
        $data = $this->data($this->controller()->show(Request::create('/x')));

        self::assertNull($data['invoice_logo_url']);
        self::assertSame('', $data['settings']['commerce.invoice.logo_blob_uuid']['value']);
        self::assertSame('', $data['settings']['commerce.invoice.footer_text']['value']);
        self::assertTrue($data['settings']['commerce.invoice.show_sku']['value']);
        self::assertTrue($data['settings']['commerce.invoice.show_addresses']['value']);
        self::assertTrue($data['settings']['commerce.invoice.show_tax_id']['value']);
        self::assertSame('a4', $data['settings']['commerce.invoice.paper_preset']['value']);

        foreach (
            [
            'commerce.invoice.logo_blob_uuid',
            'commerce.invoice.footer_text',
            'commerce.invoice.show_sku',
            'commerce.invoice.show_addresses',
            'commerce.invoice.show_tax_id',
            'commerce.invoice.paper_preset',
            ] as $key
        ) {
            self::assertFalse($data['settings'][$key]['overridden'], $key);
            self::assertSame($data['settings'][$key]['default'], $data['settings'][$key]['value'], $key);
        }
    }

    public function testBooleanTogglesRoundTripAsRealBooleansAndPersistAsCanonicalStrings(): void
    {
        $data = $this->data($this->put([
            'commerce.invoice.show_sku' => false,
            'commerce.invoice.show_addresses' => false,
            'commerce.invoice.show_tax_id' => true,
        ]));

        self::assertFalse($data['settings']['commerce.invoice.show_sku']['value']);
        self::assertFalse($data['settings']['commerce.invoice.show_addresses']['value']);
        self::assertTrue($data['settings']['commerce.invoice.show_tax_id']['value']);
        self::assertTrue($data['settings']['commerce.invoice.show_sku']['overridden']);

        self::assertSame('0', $this->storedRaw('commerce.invoice.show_sku'));
        self::assertSame('0', $this->storedRaw('commerce.invoice.show_addresses'));
        self::assertSame('1', $this->storedRaw('commerce.invoice.show_tax_id'));

        // A non-boolean input is refused, not silently coerced.
        $this->expectException(ValidationException::class);
        $this->put(['commerce.invoice.show_sku' => 'yes']);
    }

    public function testPaperPresetRoundTripsAndEnforcesTheClosedEnum(): void
    {
        foreach (['thermal_80', 'thermal_58', 'a4'] as $preset) {
            $data = $this->data($this->put(['commerce.invoice.paper_preset' => $preset]));
            self::assertSame($preset, $data['settings']['commerce.invoice.paper_preset']['value']);
        }

        $this->expectException(ValidationException::class);
        $this->put(['commerce.invoice.paper_preset' => 'letter']);
    }

    public function testFooterTextRoundTrips(): void
    {
        $data = $this->data($this->put(['commerce.invoice.footer_text' => 'Thank you for your order!']));
        self::assertSame('Thank you for your order!', $data['settings']['commerce.invoice.footer_text']['value']);
        self::assertTrue($data['settings']['commerce.invoice.footer_text']['overridden']);
    }

    public function testFooterTextRejectsAngleBracketsWithoutStripping(): void
    {
        try {
            $this->put(['commerce.invoice.footer_text' => 'Thanks <b>friend</b>!']);
            self::fail('Expected a ValidationException for a footer containing "<".');
        } catch (ValidationException $e) {
            self::assertNotNull($e->firstError('commerce.invoice.footer_text'));
        }
        self::assertNull($this->storedRaw('commerce.invoice.footer_text'));
    }

    public function testFooterTextRejectsOver500Characters(): void
    {
        $this->expectException(ValidationException::class);
        $this->put(['commerce.invoice.footer_text' => str_repeat('x', 501)]);
        self::assertNull($this->storedRaw('commerce.invoice.footer_text'));
    }

    public function testUnknownInvoiceKeyIsIgnoredByTheAllowlist(): void
    {
        $data = $this->data($this->put(['commerce.invoice.mystery_field' => 'anything']));

        self::assertArrayNotHasKey('commerce.invoice.mystery_field', $data['settings']);
        self::assertNull($this->storedRaw('commerce.invoice.mystery_field'));
    }

    // -----------------------------------------------------------------
    // Logo: accepted paths (tenancy off — the default test/single-store posture)
    // -----------------------------------------------------------------

    public function testTenancyOffValidPublicImageWithNoMediaAssetsRowIsAccepted(): void
    {
        $uuid = 'logoAccept01';
        $this->seedBlob($uuid);

        $data = $this->data($this->put(['commerce.invoice.logo_blob_uuid' => $uuid]));

        self::assertSame($uuid, $data['settings']['commerce.invoice.logo_blob_uuid']['value']);
        self::assertTrue($data['settings']['commerce.invoice.logo_blob_uuid']['overridden']);
        self::assertNotNull($data['invoice_logo_url']);

        self::assertNull(
            $this->connection()->table('media_assets')->where(['blob_uuid' => $uuid])->first(),
            'Single-store mode must never require a media_assets row.',
        );
    }

    public function testDeletingTheAcceptedBlobNullsTheUrlButPreservesTheStoredUuid(): void
    {
        $uuid = 'logoAccept02';
        $this->seedBlob($uuid);
        $this->put(['commerce.invoice.logo_blob_uuid' => $uuid]);

        $this->connection()->table('blobs')->where(['uuid' => $uuid])->update([
            'status' => 'deleted',
            'deleted_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $data = $this->data($this->controller()->show(Request::create('/x')));

        self::assertNull($data['invoice_logo_url']);
        self::assertSame($uuid, $data['settings']['commerce.invoice.logo_blob_uuid']['value']);
        self::assertTrue($data['settings']['commerce.invoice.logo_blob_uuid']['overridden']);
        self::assertSame($uuid, $this->storedRaw('commerce.invoice.logo_blob_uuid'));
    }

    // -----------------------------------------------------------------
    // Logo: rejected paths — non-image / private / inactive / deleted / missing
    // -----------------------------------------------------------------

    public function testNonImageMimeBlobIsRejected(): void
    {
        $uuid = 'logoNonImage';
        $this->seedBlob($uuid, mime: 'application/pdf');

        $this->expectException(ValidationException::class);
        $this->put(['commerce.invoice.logo_blob_uuid' => $uuid]);
    }

    public function testPrivateBlobIsRejected(): void
    {
        $uuid = 'logoPrivate1';
        $this->seedBlob($uuid, visibility: 'private');

        $this->expectException(ValidationException::class);
        $this->put(['commerce.invoice.logo_blob_uuid' => $uuid]);
    }

    public function testInactiveBlobIsRejected(): void
    {
        $uuid = 'logoInactiv1';
        $this->seedBlob($uuid, status: 'inactive');

        $this->expectException(ValidationException::class);
        $this->put(['commerce.invoice.logo_blob_uuid' => $uuid]);
    }

    public function testDeletedBlobIsRejectedAtSaveTime(): void
    {
        $uuid = 'logoDeleted1';
        $this->seedBlob($uuid, status: 'deleted', deletedAt: gmdate('Y-m-d H:i:s'));

        $this->expectException(ValidationException::class);
        $this->put(['commerce.invoice.logo_blob_uuid' => $uuid]);
    }

    public function testMissingBlobIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->put(['commerce.invoice.logo_blob_uuid' => 'logoMissing1']);
    }

    public function testUnresolvableBlobFailsEvenWhenOwnershipPasses(): void
    {
        // Public/active/image and ownership GRANTED, but the injected MediaUrlResolver still
        // can't produce a URL (e.g. uploads disabled/gated) — InvoiceLogoResolver requires BOTH,
        // so this must be refused too, never silently accepted with a dead uuid.
        $uuid = 'logoUnresol1';
        $this->seedBlob($uuid);

        $resolver = new InvoiceLogoResolver(
            $this->connection(),
            $this->alwaysAllowPolicy(),
            $this->fakeMediaUrlResolver(null),
        );

        $this->expectException(ValidationException::class);
        $this->controllerWithResolver($resolver)->update($this->putRequest([
            'commerce.invoice.logo_blob_uuid' => $uuid,
        ]));
    }

    // -----------------------------------------------------------------
    // Logo: a genuine DB/policy fault — GET degrades to null, PUT refuses loudly (never silently
    // accepts). Code-review finding: InvoiceLogoResolver::resolve() must never throw for GET, but
    // the save-time path (resolveOrFail()) must NOT swallow a real fault into an ordinary 422.
    // -----------------------------------------------------------------

    public function testGetDegradesToNullUrlWhenTheResolverFaultsWithoutThrowing(): void
    {
        // Saved earlier through the normal, working, container-bound resolver (tenancy off) —
        // the blob was fine at save time.
        $uuid = 'logoFault001';
        $this->seedBlob($uuid);
        $this->put(['commerce.invoice.logo_blob_uuid' => $uuid]);

        // Now read it back through a resolver whose policy is unwell RIGHT NOW.
        $faulted = $this->controllerWithResolver(new InvoiceLogoResolver(
            $this->connection(),
            $this->throwingPolicy(),
            $this->fakeMediaUrlResolver('https://cdn.test/logo.png'),
        ));

        $data = $this->data($faulted->show(Request::create('/x')));

        self::assertNull($data['invoice_logo_url']);
        // The stored uuid and every OTHER setting stay intact — a read-time fault must not
        // corrupt or blank out unrelated settings in the same payload.
        self::assertSame($uuid, $data['settings']['commerce.invoice.logo_blob_uuid']['value']);
        self::assertTrue($data['settings']['commerce.invoice.show_sku']['value']);
        self::assertSame('a4', $data['settings']['commerce.invoice.paper_preset']['value']);
    }

    public function testSaveTimeValidationRefusesLoudlyOnAFaultRatherThanSilentlyAccepting(): void
    {
        $uuid = 'logoFault002';
        $this->seedBlob($uuid);

        $faulted = $this->controllerWithResolver(new InvoiceLogoResolver(
            $this->connection(),
            $this->throwingPolicy(),
            $this->fakeMediaUrlResolver('https://cdn.test/logo.png'),
        ));

        try {
            $this->expectException(\RuntimeException::class);
            $faulted->update($this->putRequest(['commerce.invoice.logo_blob_uuid' => $uuid]));
        } finally {
            self::assertNull(
                $this->storedRaw('commerce.invoice.logo_blob_uuid'),
                'A DB/policy fault during save must never result in a silently accepted logo.',
            );
        }
    }

    // -----------------------------------------------------------------
    // Logo: tenancy-on ownership (the real TenantBlobPolicy, tenant-scoped by a fake resolver)
    // -----------------------------------------------------------------

    public function testTenancyOnCrossTenantLogoIsRejected(): void
    {
        $this->container()->get(SystemFlags::class)->put('tenancy.enabled', '1');

        $owned = 'logoTenantA1';
        $foreign = 'logoTenantB1';
        $this->seedBlob($owned);
        $this->seedBlob($foreign);
        $this->seedOwnership($owned, 'tenantA00001');
        $this->seedOwnership($foreign, 'tenantB00001');

        $resolver = new InvoiceLogoResolver(
            $this->connection(),
            $this->tenantScopedPolicy('tenantA00001'),
            $this->fakeMediaUrlResolver('https://cdn.test/logo.png'),
        );

        $this->expectException(ValidationException::class);
        $this->controllerWithResolver($resolver)->update($this->putRequest([
            'commerce.invoice.logo_blob_uuid' => $foreign,
        ]));
    }

    public function testTenancyOnSameTenantLogoIsAcceptedAndReturnsTheUrl(): void
    {
        $this->container()->get(SystemFlags::class)->put('tenancy.enabled', '1');

        $owned = 'logoTenantA2';
        $this->seedBlob($owned);
        $this->seedOwnership($owned, 'tenantA00001');

        $resolver = new InvoiceLogoResolver(
            $this->connection(),
            $this->tenantScopedPolicy('tenantA00001'),
            $this->fakeMediaUrlResolver('https://cdn.test/logo.png'),
        );

        $data = $this->data($this->controllerWithResolver($resolver)->update($this->putRequest([
            'commerce.invoice.logo_blob_uuid' => $owned,
        ])));

        self::assertSame($owned, $data['settings']['commerce.invoice.logo_blob_uuid']['value']);
        self::assertSame('https://cdn.test/logo.png', $data['invoice_logo_url']);
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    private function seedBlob(
        string $uuid,
        string $mime = 'image/jpeg',
        string $visibility = 'public',
        string $status = 'active',
        ?string $deletedAt = null,
    ): void {
        $row = [
            'uuid' => $uuid,
            'name' => 'logo.png',
            'mime_type' => $mime,
            'size' => 123,
            'url' => 'uploads/' . $uuid,
            'visibility' => $visibility,
            'status' => $status,
            'created_by' => self::UPLOADER,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($deletedAt !== null) {
            $row['deleted_at'] = $deletedAt;
        }
        $this->connection()->table('blobs')->insert($row);
    }

    private function seedOwnership(string $blobUuid, string $tenantUuid): void
    {
        $this->connection()->table('media_assets')->insert([
            'blob_uuid' => $blobUuid,
            'tenant_uuid' => $tenantUuid,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function alwaysAllowPolicy(): BlobAccessPolicy
    {
        return new class implements BlobAccessPolicy {
            public function authorizeAccess(array $blob, BlobAccessContext $context): bool
            {
                return true;
            }
        };
    }

    /** Simulates a genuine DB/policy fault (e.g. a connection outage) — never a normal refusal. */
    private function throwingPolicy(): BlobAccessPolicy
    {
        return new class implements BlobAccessPolicy {
            public function authorizeAccess(array $blob, BlobAccessContext $context): bool
            {
                throw new \RuntimeException('simulated policy/database fault');
            }
        };
    }

    private function fakeMediaUrlResolver(?string $url): MediaUrlResolver
    {
        return new class ($url) implements MediaUrlResolver {
            public function __construct(private readonly ?string $url)
            {
            }

            public function url(string $uuid): ?string
            {
                return $this->url;
            }
        };
    }

    /** The REAL app policy, tenant-scoped by a fake resolver instead of full tenancy bootstrap. */
    private function tenantScopedPolicy(string $currentTenant): BlobAccessPolicy
    {
        return new TenantBlobPolicy(
            $this->appContext(),
            $this->connection(),
            $this->container()->get(SystemFlags::class),
            $this->container()->get(TenantRuntimeReadiness::class),
            $this->container()->get(WriteBarrier::class),
            new class ($currentTenant) implements CurrentTenantResolver {
                public function __construct(private readonly string $uuid)
                {
                }

                public function tenantUuid(ApplicationContext $context): string
                {
                    return $this->uuid;
                }
            },
        );
    }

    private function controllerWithResolver(InvoiceLogoResolver $resolver): CommerceSettingsController
    {
        return new CommerceSettingsController(
            $this->appContext(),
            $this->container()->get(CommerceTenantResolution::class),
            $this->container()->get(VariantRepository::class),
            null,
            $this->container()->get(CommerceSettingsStore::class),
            null,
            $resolver,
        );
    }

    private function controller(): CommerceSettingsController
    {
        return $this->container()->get(CommerceSettingsController::class);
    }

    /** @param array<string,mixed> $body */
    private function putRequest(array $body): Request
    {
        return Request::create(
            '/x',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body),
        );
    }

    /** @param array<string,mixed> $body */
    private function put(array $body): Response
    {
        return $this->controller()->update($this->putRequest($body));
    }

    private function storedRaw(string $key): ?string
    {
        $row = $this->connection()->table('settings')->where(['key' => $key])->first();

        return is_array($row) ? (string) ($row['value'] ?? '') : null;
    }

    /** @return array<string,mixed> */
    private function data(Response $res): array
    {
        return (array) json_decode((string) $res->getContent(), true)['data'];
    }
}
