<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

/**
 * A site laid out as `thallo:provision` leaves an enabled one (spec §16.1/§16.4): the private and
 * control folders with their modes, the admission lock, the guard installed and included, the
 * restart confirmed after it, and the installed version in composer.lock.
 */
trait UpdaterSite
{
    private function layOutUpdaterSite(string $base, string $installed = '1.0.0-beta.101'): void
    {
        chmod($base, 0755);
        foreach (['storage', 'storage/framework'] as $dir) {
            @mkdir($base . '/' . $dir, 0755, true);
            chmod($base . '/' . $dir, 0755);
        }
        @mkdir($base . '/storage/framework/updates', 02770, true);
        @mkdir($base . '/.thallo-update', 0700);
        chmod($base . '/.thallo-update', 0700);
        @mkdir($base . '/.thallo-update-control', 0750);
        chmod($base . '/.thallo-update-control', 0750);
        touch($base . '/.thallo-update-control/admission.lock');
        chmod($base . '/.thallo-update-control/admission.lock', 0640);
        file_put_contents($base . '/.thallo-update/guard.installed', '100');
        // The owner confirmed the restart after the guard was installed.
        file_put_contents($base . '/.thallo-update/guard.ready', '200');
        @mkdir($base . '/bootstrap', 0755, true);
        @mkdir($base . '/public', 0755, true);
        file_put_contents($base . '/bootstrap/update-guard.php', '<?php');
        foreach (['public/index.php' => '/../bootstrap', 'glueful' => '/bootstrap'] as $entry => $relative) {
            $existing = is_file($base . '/' . $entry) ? (string) file_get_contents($base . '/' . $entry) : "<?php\n";
            if (!str_contains($existing, 'update-guard.php')) {
                $include = "require __DIR__ . '{$relative}/update-guard.php';\n";
                $declare = "declare(strict_types=1);\n";
                $after = str_contains($existing, $declare) ? $declare : "<?php\n";
                $at = strpos($existing, $after) + strlen($after);
                file_put_contents($base . '/' . $entry, substr($existing, 0, $at) . $include . substr($existing, $at));
            }
        }
        $lock = json_decode((string) @file_get_contents($base . '/composer.lock'), true);
        $lock = is_array($lock) ? $lock : [];
        $lock['packages'] = array_values(array_filter(
            (array) ($lock['packages'] ?? []),
            static fn ($p): bool => ($p['name'] ?? '') !== 'glueful/thallo-core',
        ));
        $lock['packages'][] = ['name' => 'glueful/thallo-core', 'version' => $installed];
        file_put_contents($base . '/composer.lock', json_encode($lock));
    }

    /** What the admin's start endpoint files in the inbox. @param array<string,mixed> $override */
    private function fileStartRequest(string $base, string $target, string $token, array $override = []): void
    {
        $request = array_merge([
            'target' => $target,
            'from' => '1.0.0-beta.101',
            'requested_by' => 'owner-uuid',
            'requested_at' => gmdate('c'),
            'token_hash' => hash('sha256', $token),
        ], $override);
        file_put_contents($base . '/storage/framework/updates/start.json', json_encode(array_filter(
            $request,
            static fn ($v): bool => $v !== null,
        )));
    }

    /** What /_update files in the inbox for a recovery choice. */
    private function fileRecoverRequest(string $base, string $option, string $token): void
    {
        file_put_contents(
            $base . '/storage/framework/updates/recover.json',
            json_encode(['option' => $option, 'token' => $token]),
        );
    }
}
