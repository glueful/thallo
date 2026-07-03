<?php

declare(strict_types=1);

namespace App\Setup;

use App\Content\Repositories\ContentTypeRepository;
use Glueful\Auth\PasswordHasher;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Users\Repositories\UserRepository;

/**
 * Single source of truth for first-run installation.
 *
 * Creates the first admin user, writes site settings, and marks the instance as
 * installed by setting the `installed` key in `lemma_settings`. Intentionally
 * HTTP-agnostic: both the web setup endpoint and the `lemma:setup` CLI command
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
     * Returns true when the `installed` marker has been written to `lemma_settings`.
     */
    public function isInstalled(): bool
    {
        $row = $this->db->table('lemma_settings')
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
     *   4. Writes site_name and default_locale to lemma_settings.
     *   5. Seeds a generic "Pages" content type so a fresh instance is immediately editable.
     *   6. Writes the `installed` marker to lemma_settings.
     *
     * @throws \RuntimeException  When the instance is already installed.
     * @throws \InvalidArgumentException When user creation fails validation.
     */
    public function install(
        string $siteName,
        string $adminEmail,
        string $adminPassword,
        string $locale,
    ): void {
        $this->db->transaction(function () use ($siteName, $adminEmail, $adminPassword, $locale): void {
            // Reduces the race window: a concurrent request that completed install between
            // the caller's isInstalled() check and this point will be caught here. This does
            // not fully close the race (no row lock), but avoids the common double-install case.
            if ($this->isInstalled()) {
                throw new \RuntimeException('Lemma is already installed.');
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

            $adminRoleSlug = (string) config($this->context, 'lemma.roles.admin', 'administrator');

            $this->aegis->assignRole($userUuid, $adminRoleSlug);

            $this->put('site_name', $siteName);
            $this->put('default_locale', $locale);

            // Seed a generic "Pages" content type so a fresh instance has a working
            // editorial loop on day one. This is an ORDINARY content-type row — fully
            // editable, renameable, and deletable like any user-defined type, not a
            // hardcoded/system type — which keeps Lemma's "define your own types" model
            // intact. Shares this transaction via the singleton Connection.
            $this->contentTypes->create([
                'slug'        => 'page',
                'name'        => 'Pages',
                'description' => 'Generic static pages (e.g. About, Contact).',
                'schema'      => [
                    ['name' => 'title', 'type' => 'string', 'required' => true],
                    ['name' => 'body',  'type' => 'blocks', 'required' => true],
                ],
                'created_by'  => $userUuid,
            ]);

            $this->put('installed', '1');
        });
    }

    /**
     * Inserts or updates a single key in `lemma_settings`.
     *
     * Because the PostgreSQL upsert helper targets `ON CONFLICT (id)` and our primary
     * key is the varchar `key` column, we perform a manual check-then-write instead.
     * Both branches run inside the caller's transaction when invoked from install().
     */
    private function put(string $key, string $value): void
    {
        $now = date('Y-m-d H:i:s');

        $existing = $this->db->table('lemma_settings')
            ->where(['key' => $key])
            ->first();

        if ($existing === null) {
            $this->db->table('lemma_settings')->insert([
                'key'        => $key,
                'value'      => $value,
                'updated_at' => $now,
            ]);
        } else {
            $this->db->table('lemma_settings')
                ->where(['key' => $key])
                ->update([
                    'value'      => $value,
                    'updated_at' => $now,
                ]);
        }
    }
}
