<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support\Capabilities;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Capability\DeclaresCapabilities;
use Thallo\Contracts\Capability\ManagementMode;

/**
 * Declares `test.contested` (an activation over glueful/media) and, in boot(), registers a route
 * only while it is on — the capability-dependent boot work a later conflicting declaration must
 * not be able to undo.
 */
final class ContestedConsumerProvider extends ServiceProvider implements DeclaresCapabilities
{
    public static ?bool $decision = null;

    public function capabilities(): array
    {
        return [new Capability(
            'test.contested',
            label: 'Contested',
            owningPackage: 'glueful/media',
            management: ManagementMode::Activation,
        )];
    }

    public function boot(ApplicationContext $context): void
    {
        self::$decision = app($context, CapabilityRegistry::class)->isEnabled('test.contested');
        if (self::$decision) {
            $this->loadRoutesFrom(dirname(__DIR__, 2) . '/fixtures/routes/contested.php');
        }
    }
}
