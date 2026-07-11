<?php

declare(strict_types=1);

namespace App\Tests\Integration\Setup;

use App\Setup\SetupService;
use App\Support\RoleAuthority;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Thallo\Contracts\Settings\SystemChannel;

/**
 * Verifies the SetupService install flow end-to-end against a real PostgreSQL database.
 *
 * Requires `composer test:migrate` to have run first (settings table must exist).
 */
final class SetupServiceTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Start each test from a clean slate. The users table is uuid-keyed (no `id`
        // column), so TRUNCATE ... CASCADE is the reliable wipe — it clears users, the
        // Aegis user_roles child rows, and the settings markers regardless of PK.
        $this->connection()->getPDO()->exec('TRUNCATE TABLE users, user_roles, settings CASCADE');
    }

    protected function tearDown(): void
    {
        // install() now provisions a committed superuser; wipe after the LAST test too so no
        // stray superuser survives this class and pollutes global-count invariants elsewhere
        // (e.g. last-superuser continuity checks that assert against the whole users table).
        $this->connection()->getPDO()->exec('TRUNCATE TABLE users, user_roles, settings CASCADE');
        parent::tearDown();
    }

    private function service(): SetupService
    {
        return $this->container()->get(SetupService::class);
    }

    private function channel(): SystemChannel
    {
        return $this->container()->get(SystemChannel::class);
    }

    public function testIsInstalledReturnsFalseOnFreshInstall(): void
    {
        self::assertFalse($this->service()->isInstalled());
    }

    public function testInstallCreatesAdminAndSetsInstalledMarker(): void
    {
        $svc = $this->service();

        $svc->install(
            siteName: 'Thallo Test Site',
            adminEmail: 'admin@example.com',
            adminPassword: 'S3cur3P@ssw0rd!',
            locale: 'en',
        );

        self::assertTrue($svc->isInstalled());

        // Verify the admin password is stored as a hash, never as plaintext.
        $userRow = $this->connection()->table('users')
            ->where(['email' => 'admin@example.com'])
            ->first();

        self::assertNotNull($userRow, 'admin user must exist after install');
        self::assertNotSame('S3cur3P@ssw0rd!', $userRow['password'], 'password must not be stored plaintext');
        self::assertTrue(
            password_verify('S3cur3P@ssw0rd!', (string) $userRow['password']),
            'stored hash must verify against the original password',
        );

        $uuid = (string) $userRow['uuid'];
        $roleSlugs = array_map(
            static fn ($role): string => $role->getSlug(),
            $this->container()->get(AegisPermissionProvider::class)->getUserRoles($uuid),
        );
        self::assertContains('superuser', $roleSlugs);
        self::assertContains('administrator', $roleSlugs);
        self::assertTrue($this->container()->get(RoleAuthority::class)->isCanonicalSuperuser($uuid));

        // Verify site_name was written to settings.
        $row = $this->connection()->table('settings')
            ->where(['key' => 'site_name'])
            ->first();

        self::assertNotNull($row);
        self::assertSame('Thallo Test Site', $row['value']);

        // Verify default_locale was written.
        $localeRow = $this->connection()->table('settings')
            ->where(['key' => 'default_locale'])
            ->first();

        self::assertNotNull($localeRow);
        self::assertSame('en', $localeRow['value']);
    }

    public function testInstallSeedsGenericPagesContentType(): void
    {
        $svc = $this->service();

        $svc->install(
            siteName: 'Thallo Test Site',
            adminEmail: 'admin@example.com',
            adminPassword: 'S3cur3P@ssw0rd!',
            locale: 'en',
        );

        // A fresh instance must ship with the seeded "Pages" type so the editorial loop
        // works on day one. It is an ordinary content-type row (status active, not a
        // system type), with the generic title + body schema.
        $type = (new \App\Content\Repositories\ContentTypeRepository($this->connection()))
            ->findBySlug('pages');

        self::assertNotNull($type, 'fresh install must seed the "pages" content type');
        self::assertSame('Pages', $type['name']);
        self::assertSame('active', $type['status']);

        $fieldNames = array_map(static fn(array $f): string => (string) $f['name'], $type['schema']);
        self::assertSame(['title', 'body'], $fieldNames);

        // The title field is required (it drives the entry display title) and body is
        // a BLOCKS field — seeded pages are block-built (the block builder is the
        // page-composition surface, not a plain text column).
        $byName = [];
        foreach ($type['schema'] as $field) {
            $byName[$field['name']] = $field;
        }
        self::assertSame('string', $byName['title']['type']);
        self::assertTrue((bool) ($byName['title']['required'] ?? false));
        self::assertSame('blocks', $byName['body']['type']);
        self::assertTrue((bool) ($byName['body']['required'] ?? false));

        // Renderable out of the box: pages are publicly delivered AND mounted
        // at root (/about, not /page/about).
        self::assertTrue((bool) $type['public_delivery']);
        self::assertTrue((bool) $type['mount_at_root']);

        // The companion "Posts" type: publicly delivered, PREFIXED grammar
        // (/post/hello — a blog shape), title/excerpt/cover/body schema.
        $posts = (new \App\Content\Repositories\ContentTypeRepository($this->connection()))
            ->findBySlug('post');
        self::assertNotNull($posts, 'fresh install must seed the "post" content type');
        self::assertSame('Posts', $posts['name']);
        self::assertTrue((bool) $posts['public_delivery']);
        self::assertFalse((bool) $posts['mount_at_root']);
        self::assertSame(
            ['title', 'excerpt', 'cover', 'body', 'categories'],
            array_map(static fn(array $f): string => (string) $f['name'], $posts['schema']),
        );

        // The taxonomy worked-example (taxonomy-defaults direction): posts carry
        // a FILTERABLE multi reference to the seeded "category" type — exactly
        // the properties the /post/categories/{slug} archive grammar gates on.
        $catField = null;
        foreach ($posts['schema'] as $field) {
            if ($field['name'] === 'categories') {
                $catField = $field;
            }
        }
        self::assertNotNull($catField);
        self::assertSame('reference', $catField['type']);
        self::assertSame('category', $catField['reference_type']);
        self::assertTrue((bool) ($catField['multiple'] ?? false));
        self::assertTrue((bool) ($catField['filterable'] ?? false));

        $category = (new \App\Content\Repositories\ContentTypeRepository($this->connection()))
            ->findBySlug('category');
        self::assertNotNull($category, 'fresh install must seed the "category" content type');
        self::assertTrue((bool) $category['public_delivery']);
        // Term slugs resolve via the target's `slug` FIELD (reference_slug_field default).
        self::assertSame(
            ['title', 'slug'],
            array_map(static fn(array $f): string => (string) $f['name'], $category['schema']),
        );

        // And the render allowlist row: without it, /post and the archives 404.
        $row = $this->connection()->table('settings')
            ->where(['key' => 'listing_types'])->first();
        self::assertSame('post', $row['value'] ?? null);

        // admin_url is a system key: it never lands in `settings`, and none was passed
        // (CLI-style install), so the system channel holds no value for it either.
        self::assertNull(
            $this->connection()->table('settings')->where(['key' => 'admin_url'])->first(),
        );
        self::assertNull($this->channel()->get('admin_url'));
    }

    public function testInstallRecordsTheAdminOriginWhenSupplied(): void
    {
        // The web setup form sends the SPA's own origin — persisted so the
        // preview bar's Edit/Design links work with zero configuration.
        $this->service()->install(
            siteName: 'S',
            adminEmail: 'admin@example.com',
            adminPassword: 'S3cur3P@ssw0rd!',
            locale: 'en',
            adminUrl: 'https://admin.example.com/',
        );
        // admin_url is a system key — persisted to the unscoped system channel, not `settings`.
        self::assertNull($this->connection()->table('settings')->where(['key' => 'admin_url'])->first());
        self::assertSame('https://admin.example.com', $this->channel()->get('admin_url')); // trailing / trimmed
    }

    public function testInstallIsPermanentLock(): void
    {
        $svc = $this->service();

        $svc->install(
            siteName: 'First Install',
            adminEmail: 'admin2@example.com',
            adminPassword: 'S3cur3P@ssw0rd!',
            locale: 'en',
        );

        self::assertTrue($svc->isInstalled());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Thallo is already installed.');

        $svc->install(
            siteName: 'Second Attempt',
            adminEmail: 'other@example.com',
            adminPassword: 'AnotherPass!',
            locale: 'fr',
        );
    }
}
