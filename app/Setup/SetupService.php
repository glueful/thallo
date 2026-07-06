<?php

declare(strict_types=1);

namespace App\Setup;

use App\Content\Regions\RegionRepository;
use App\Content\Repositories\ContentTypeRepository;
use Glueful\Auth\PasswordHasher;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Helpers\Utils;

/**
 * Single source of truth for first-run installation.
 *
 * Creates the first admin user, writes site settings, and marks the instance as
 * installed by setting the `installed` key in `settings`. Intentionally
 * HTTP-agnostic: both the web setup endpoint and the `thallo:setup` CLI command
 * call this service directly.
 */
final class SetupService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $db,
        private readonly UserRepository $users,
        private readonly AegisPermissionProvider $aegis,
        private readonly ContentTypeRepository $contentTypes,
    ) {
    }

    /**
     * Returns true when the `installed` marker has been written to `settings`.
     */
    public function isInstalled(): bool
    {
        $row = $this->db->table('settings')
            ->where(['key' => 'installed'])
            ->first();

        return $row !== null && ($row['value'] ?? '') === '1';
    }

    /**
     * Runs the full first-time install inside a single database transaction.
     *
     * Steps:
     *   1. Re-checks isInstalled() to guard against races.
     *   2. Creates the admin user via UserRepository.
     *   3. Assigns the configured admin role slug to the new user via AegisPermissionProvider.
     *   4. Writes site_name and default_locale to settings.
     *   5. Seeds "Pages" (publicly delivered, mounted at root), "Posts"
     *      (publicly delivered, prefixed) and "Categories" (the taxonomy
     *      worked-example: posts carry a filterable `categories` reference, so
     *      /post/categories/{slug} archives work) content types, and writes
     *      the `listing_types` setting (post) so listings/archives resolve —
     *      a fresh instance is immediately editable AND renderable.
     *   6. Writes the `installed` marker to settings.
     *
     * @throws \RuntimeException  When the instance is already installed.
     * @throws \InvalidArgumentException When user creation fails validation.
     */
    public function install(
        string $siteName,
        string $adminEmail,
        string $adminPassword,
        string $locale,
        ?string $adminUrl = null,
    ): void {
        $this->db->transaction(function () use (
            $siteName,
            $adminEmail,
            $adminPassword,
            $locale,
            $adminUrl,
        ): void {
            // Reduces the race window: a concurrent request that completed install between
            // the caller's isInstalled() check and this point will be caught here. This does
            // not fully close the race (no row lock), but avoids the common double-install case.
            if ($this->isInstalled()) {
                throw new \RuntimeException('Thallo is already installed.');
            }

            $hashed = (new PasswordHasher())->hash($adminPassword);

            // Use the email as the username so the first admin is unique (the users table
            // enforces both username and email uniqueness) and matches the web setup flow,
            // which collects an email but no separate username. The email is pre-verified: the
            // operator creates this account during first-run setup, so there is no one to send a
            // verification email to — mark it verified now.
            $userUuid = $this->users->create([
                'username'          => $adminEmail,
                'email'             => $adminEmail,
                'password'          => $hashed,
                'status'            => 'active',
                'email_verified_at' => date('Y-m-d H:i:s'),
            ]);

            $adminRoleSlug = (string) config($this->context, 'thallo.roles.admin', 'administrator');

            $this->aegis->assignRole($userUuid, $adminRoleSlug);

            $this->put('site_name', $siteName);
            $this->put('default_locale', $locale);
            // The web setup form sends the admin SPA's own origin, so the
            // preview bar's Edit/Design links work with zero configuration.
            if (is_string($adminUrl) && preg_match('#\Ahttps?://#i', $adminUrl) === 1) {
                $this->put('admin_url', rtrim($adminUrl, '/'));
            }

            // Seed "Pages" and "Posts" content types so a fresh instance has a
            // working editorial loop on day one. These are ORDINARY content-type
            // rows — fully editable, renameable, and deletable like any
            // user-defined type, not hardcoded/system types — which keeps
            // Thallo's "define your own types" model intact. Both are publicly
            // deliverable out of the box; pages mount at root (/about), posts
            // keep the prefixed grammar (/post/hello) like a blog.
            // Shares this transaction via the singleton Connection.
            $this->contentTypes->create([
                'slug'            => 'pages',
                'name'            => 'Pages',
                'description'     => 'Generic static pages (e.g. About, Contact).',
                'public_delivery' => true,
                'mount_at_root'   => true,
                'schema'          => [
                    ['name' => 'title', 'type' => 'string', 'required' => true],
                    ['name' => 'body',  'type' => 'blocks', 'required' => true],
                ],
                'created_by'      => $userUuid,
            ]);
            // Taxonomies are ORDINARY content types + filterable reference
            // fields — this is the worked example of that pattern (deliberately
            // ONE taxonomy: a second is a two-minute copy of the same recipe).
            // Term slugs resolve via the target's published `slug` field
            // (reference_slug_field default), and the archive grammar
            // (/post/categories/{slug}) requires the reference field to be
            // filterable and both types publicly delivered.
            $this->contentTypes->create([
                'slug'            => 'category',
                'name'            => 'Categories',
                'description'     => 'Groups posts into browsable archives.',
                'public_delivery' => true,
                'mount_at_root'   => false,
                'schema'          => [
                    ['name' => 'title', 'type' => 'string', 'required' => true],
                    ['name' => 'slug',  'type' => 'string', 'required' => true],
                ],
                'created_by'      => $userUuid,
            ]);
            $this->contentTypes->create([
                'slug'            => 'post',
                'name'            => 'Posts',
                'description'     => 'Dated articles and news (e.g. blog posts).',
                'public_delivery' => true,
                'mount_at_root'   => false,
                'schema'          => [
                    ['name' => 'title',      'type' => 'string', 'required' => true],
                    ['name' => 'excerpt',    'type' => 'text'],
                    ['name' => 'body',       'type' => 'blocks', 'required' => true],
                    ['name' => 'categories', 'type' => 'reference', 'reference_type' => 'category',
                        'multiple' => true, 'filterable' => true],
                ],
                'created_by'      => $userUuid,
            ]);

            // Listings/archives are allowlist-gated: without this row a fresh
            // install's /post and /post/categories/{slug} would 404 — the
            // half-working-default trap. A DB setting (editable in Settings →
            // General) since the taxonomy-defaults work.
            $this->put('listing_types', 'post');

            // Default chrome regions (global-regions spec §9): reproduce the
            // theme's hardcoded look so fresh installs are region-editable
            // from minute one. Structured sources by construction — the logo
            // block reads Settings → General, navigation renders the 'main'
            // menu. Existing installs never get these (install() only); their
            // layouts keep the hardcoded fallback until a region is saved.
            $regions = app($this->context, RegionRepository::class);
            $regions->save('header', [
                ['id' => Utils::generateNanoID(12), 'type' => 'logo',
                    'data' => ['size' => 'medium', 'link_home' => true]],
                ['id' => Utils::generateNanoID(12), 'type' => 'navigation',
                    'data' => ['menu' => 'main']],
            ], ['sticky' => false, 'width' => 'contained'], $userUuid);
            $regions->save('footer', [
                ['id' => Utils::generateNanoID(12), 'type' => 'rich_text',
                    'data' => ['body' => '<p>' . htmlspecialchars($siteName, ENT_QUOTES) . '</p>']],
            ], ['width' => 'contained'], $userUuid);

            $this->put('installed', '1');
        });
    }

    /**
     * Inserts or updates a single key in `settings`.
     *
     * Because the PostgreSQL upsert helper targets `ON CONFLICT (id)` and our primary
     * key is the varchar `key` column, we perform a manual check-then-write instead.
     * Both branches run inside the caller's transaction when invoked from install().
     */
    private function put(string $key, string $value): void
    {
        $now = date('Y-m-d H:i:s');

        $existing = $this->db->table('settings')
            ->where(['key' => $key])
            ->first();

        if ($existing === null) {
            $this->db->table('settings')->insert([
                'key'        => $key,
                'value'      => $value,
                'updated_at' => $now,
            ]);
        } else {
            $this->db->table('settings')
                ->where(['key' => $key])
                ->update([
                    'value'      => $value,
                    'updated_at' => $now,
                ]);
        }
    }
}
