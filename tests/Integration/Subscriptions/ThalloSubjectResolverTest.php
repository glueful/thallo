<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriptions;

use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioner;
use Glueful\Extensions\Subscriptions\Contracts\SubjectResolverInterface;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubjectType;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Helpers\Utils;
use Thallo\Subscriptions\Resolver\ThalloSubjectResolver;
use Thallo\Tenancy\System\SystemFlags;
use Thallo\Tenancy\Tenant\SingleStoreTenant;

/**
 * Task 6 (thallo-subscriptions spec §4): `ThalloSubjectResolver` is the tenant-only host
 * binding of the engine's `SubjectResolverInterface` seam.
 *
 * The tenancy-ON coverage below hand-builds a {@see SingleStoreTenant} with a fake
 * {@see CurrentTenantResolver} rather than booting the real glueful/tenancy enforcement
 * provider -- mirroring TenantResolutionModesTest's established "mode (c), fake resolver, no
 * dev-link needed" pattern. This proves ThalloSubjectResolver's DELEGATION (it forwards
 * whatever SingleStoreTenant::resolve() answers) without re-testing SingleStoreTenant's own
 * mode-branching, which is that class's own responsibility (see SingleStoreTenantTest and
 * CommerceIntegrationServiceProvider's TenantResolutionModesTest for the precedent).
 */
final class ThalloSubjectResolverTest extends AppTestCase
{
    /** @var list<string> tenant uuids this test created, cleaned up in tearDown */
    private array $seededTenants = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetTenantState();
        $this->seededTenants = [];
    }

    protected function tearDown(): void
    {
        $this->resetTenantState();
        parent::tearDown();
    }

    private function resetTenantState(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec('TRUNCATE TABLE tenant_memberships, tenants, users CASCADE');
        $pdo->exec("DELETE FROM thallo_system_flags WHERE key LIKE 'tenancy.%'");
        $this->container()->get(SystemFlags::class)->clearCache();
        $this->seededTenants = [];
    }

    // --- Wiring: the container binding must resolve THIS class, not the engine's default ----

    public function testContainerBindingResolvesThalloSubjectResolverNotTheEngineDefault(): void
    {
        self::assertInstanceOf(
            ThalloSubjectResolver::class,
            $this->container()->get(SubjectResolverInterface::class),
        );
    }

    // --- currentTenant(): delegates SOLELY to SingleStoreTenant::resolve() -------------------

    public function testTenancyOffCurrentTenantReturnsTheDefaultWorkspaceUuid(): void
    {
        $owner = $this->createUser('off@example.com');
        $expected = $this->container()->get(SingleStoreTenant::class)
            ->ensure('default', 'Default', $owner);

        $resolver = $this->container()->get(ThalloSubjectResolver::class);

        self::assertSame($expected, $resolver->currentTenant($this->appContext()));
    }

    public function testTenancyOnCurrentTenantFollowsTheCurrentWorkspaceThroughSingleStoreTenant(): void
    {
        $context = $this->appContext();
        $flags = $this->container()->get(SystemFlags::class);
        $singleStore = new SingleStoreTenant(
            $context,
            $this->connection(),
            $flags,
            $this->container()->get(TenantProvisioner::class),
            $this->fakeCurrentTenantResolver('tenOn0000001'),
        );
        $resolver = new ThalloSubjectResolver(
            $context,
            $singleStore,
            $this->container()->get(TenantAdministration::class),
        );

        $flags->put('tenancy.enabled', '1');

        self::assertSame('tenOn0000001', $resolver->currentTenant($context));
    }

    // --- currentUser(): always null (memberships stay inert) ----------------------------------

    public function testCurrentUserAlwaysReturnsNull(): void
    {
        $resolver = $this->container()->get(ThalloSubjectResolver::class);

        self::assertNull($resolver->currentUser($this->appContext()));
    }

    // --- validate(): tenant self-subjects, proven against the real TenantAdministration -------

    public function testValidateReturnsTrueForACoherentTenantSelfSubjectWithAnExistingTenant(): void
    {
        $tenant = $this->seedTenant('coherent');
        $resolver = $this->container()->get(ThalloSubjectResolver::class);

        self::assertTrue($resolver->validate($this->appContext(), Subject::tenant($tenant)));
    }

    public function testValidateReturnsFalseForAWellFormedButNonexistentTenantUuid(): void
    {
        $resolver = $this->container()->get(ThalloSubjectResolver::class);
        $nonexistent = Utils::generateNanoID();

        self::assertFalse($resolver->validate($this->appContext(), Subject::tenant($nonexistent)));
    }

    public function testValidateReturnsFalseWhenSubjectUuidDiffersFromTenantUuid(): void
    {
        $tenant = $this->seedTenant('mismatch');
        $resolver = $this->container()->get(ThalloSubjectResolver::class);
        $subject = new Subject($tenant, SubjectType::TENANT, Utils::generateNanoID());

        self::assertFalse($resolver->validate($this->appContext(), $subject));
    }

    public function testValidateReturnsFalseForEmptyIds(): void
    {
        $resolver = $this->container()->get(ThalloSubjectResolver::class);
        $subject = new Subject('', SubjectType::TENANT, '');

        self::assertFalse($resolver->validate($this->appContext(), $subject));
    }

    public function testValidateReturnsFalseForAUserSubjectEvenWhenItWouldOtherwiseBeCoherent(): void
    {
        $tenant = $this->seedTenant('user-subject');
        $resolver = $this->container()->get(ThalloSubjectResolver::class);

        self::assertFalse(
            $resolver->validate($this->appContext(), Subject::user($tenant, Utils::generateNanoID())),
        );
    }

    // --- TenantAdministration is mandatory: no shape-only fallback when it's unavailable ------

    public function testConstructorRequiresTenantAdministrationNonNullableWithNoDefault(): void
    {
        $ctor = new \ReflectionMethod(ThalloSubjectResolver::class, '__construct');
        $tenants = null;
        foreach ($ctor->getParameters() as $param) {
            if ($param->getName() === 'tenants') {
                $tenants = $param;
            }
        }

        self::assertNotNull($tenants, 'ThalloSubjectResolver must take a $tenants constructor parameter');
        self::assertFalse(
            $tenants->allowsNull(),
            'TenantAdministration must be non-nullable -- there is no coherent-shape fallback '
            . 'for when the tenant authority is unavailable',
        );
        self::assertFalse(
            $tenants->isDefaultValueAvailable(),
            'TenantAdministration must have no default -- construction must fail rather than '
            . 'silently accepting shape-only subjects',
        );
        $type = $tenants->getType();
        self::assertNotNull($type);
        self::assertSame(TenantAdministration::class, (string) $type);
    }

    // --- helpers -------------------------------------------------------------------------------

    private function createUser(string $email): string
    {
        return $this->container()->get(UserRepository::class)->create([
            'username' => $email,
            'email' => $email,
            'password' => password_hash('test-password', PASSWORD_DEFAULT),
            'status' => 'active',
            'email_verified_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function seedTenant(string $slugSuffix): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('tenants')->insert([
            'uuid' => $uuid,
            'slug' => 'tsr-' . $slugSuffix . '-' . substr($uuid, 0, 6),
            'name' => 'TSR ' . $slugSuffix,
            'status' => 'active',
        ]);
        $this->seededTenants[] = $uuid;

        return $uuid;
    }

    private function fakeCurrentTenantResolver(string $tenantUuid): CurrentTenantResolver
    {
        return new class ($tenantUuid) implements CurrentTenantResolver {
            public function __construct(private readonly string $tenantUuid)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->tenantUuid;
            }
        };
    }
}
