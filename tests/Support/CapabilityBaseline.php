<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

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

    public static function restore(\PDO $pdo): void
    {
        $put = $pdo->prepare(
            "INSERT INTO thallo_system_flags (key, value, updated_at) VALUES (?, 'true', ?)
             ON CONFLICT (key) DO UPDATE SET value = 'true', updated_at = EXCLUDED.updated_at"
        );
        $off = self::off();
        foreach (array_diff(self::ON, $off) as $id) {
            $put->execute(["capability.{$id}.enabled", gmdate('Y-m-d H:i:s')]);
        }
        $forget = $pdo->prepare('DELETE FROM thallo_system_flags WHERE key = ?');
        foreach ($off as $id) {
            $forget->execute(["capability.{$id}.enabled"]);
        }
    }
}
