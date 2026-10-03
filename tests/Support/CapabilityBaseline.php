<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Thallo\Core\Capabilities\CapabilityStateVersion;

/**
 * The test install is a site that has activated its engine-backed capabilities: their switches are
 * stored on, as a finalized activation (or the upgrade adoption) leaves them. Activation capabilities
 * never follow their engine (spec §7.3a), so without this the testing overlay's enabled engines would
 * leave Commerce and Subscriptions off. scripts/run-test-migrations.php writes it, and a test that
 * clears these switches puts them back with restore().
 */
final class CapabilityBaseline
{
    public const ON = ['thallo.commerce', 'thallo.payments', 'thallo.subscriptions'];

    /**
     * Capabilities a whole run leaves off: THALLO_TEST_CAPABILITIES_OFF (comma-separated ids), used
     * by the Payments-off continuity run.
     *
     * @return list<string>
     */
    public static function off(): array
    {
        $raw = getenv('THALLO_TEST_CAPABILITIES_OFF');
        return $raw === false ? [] : array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Puts the baseline back. A change to any switch advances the capability-state version, as the
     * application's own writes do, so a route table compiled under the old state is never reused.
     */
    public static function restore(\PDO $pdo): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $put = $pdo->prepare(
            "INSERT INTO thallo_system_flags (key, value, updated_at) VALUES (?, 'true', ?)
             ON CONFLICT (key) DO UPDATE SET value = 'true', updated_at = EXCLUDED.updated_at
             WHERE thallo_system_flags.value IS DISTINCT FROM 'true'"
        );
        $changed = 0;
        $off = self::off();
        foreach (array_diff(self::ON, $off) as $id) {
            $put->execute(["capability.{$id}.enabled", $now]);
            $changed += $put->rowCount();
        }
        $forget = $pdo->prepare('DELETE FROM thallo_system_flags WHERE key = ?');
        foreach ($off as $id) {
            $forget->execute(["capability.{$id}.enabled"]);
            $changed += $forget->rowCount();
        }
        if ($changed > 0) {
            self::advanceVersion($pdo);
        }
    }

    /** Advances the capability-state version, as CapabilityStateVersion::advance() does. */
    public static function advanceVersion(\PDO $pdo): void
    {
        $pdo->prepare(
            "INSERT INTO thallo_system_flags (key, value, updated_at) VALUES (?, '1', ?)
             ON CONFLICT (key) DO UPDATE SET
               value = (COALESCE(NULLIF(thallo_system_flags.value, ''), '0')::bigint + 1)::text,
               updated_at = EXCLUDED.updated_at"
        )->execute([CapabilityStateVersion::KEY, gmdate('Y-m-d H:i:s')]);
    }
}
