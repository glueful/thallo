<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Thallo\Core\Content\Starter\Kinds\BlockTypeKind;

/**
 * For tests that turn Commerce on through its activation: no Commerce blocks, an idle activation
 * and no stored switch. Call it in setUp (an earlier run may have left an activation open) and in
 * tearDown.
 */
trait ResetsCommerceActivation
{
    protected function resetCommerceActivation(): void
    {
        $pdo = $this->connection()->getPDO();
        $slugs = array_map(
            static fn ($d): string => $d->definitionKey,
            $this->container()->get(BlockTypeKind::class)->contributionsFor('thallo.commerce'),
        );
        if ($slugs !== []) {
            $in = implode(',', array_fill(0, count($slugs), '?'));
            $pdo->prepare("DELETE FROM block_types WHERE slug IN ({$in})")->execute($slugs);
        }
        $pdo->exec("DELETE FROM capability_activation_events WHERE capability = 'thallo.commerce'");
        $pdo->exec(
            "UPDATE capability_activations SET generation = 0, status = 'idle', steps_done = '[]',
               failed_step = NULL, error = NULL, remedy = NULL, owner_token = NULL,
               lease_expires_at = NULL, workspaces = '{}', result = '{}'
             WHERE capability = 'thallo.commerce'"
        );
        $pdo->exec("DELETE FROM thallo_system_flags WHERE key = 'capability.thallo.commerce.enabled'");
    }
}
