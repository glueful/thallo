<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support\Capabilities;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Capability\DeclaresCapabilities;

/**
 * A test provider that declares a capability and consumes the capability set in its own boot(), as
 * Commerce and Render do: it records what the registry knew at that moment.
 */
final class EarlyConsumerProvider extends ServiceProvider implements DeclaresCapabilities
{
    /** @var list<string> the registered ids when this provider booted */
    public static array $seen = [];
    public static ?bool $decision = null;

    public function capabilities(): array
    {
        return [new Capability('test.early', label: 'Early')];
    }

    public function boot(ApplicationContext $context): void
    {
        $registry = app($context, CapabilityRegistry::class);
        self::$decision = $registry->isEnabled('test.early');
        self::$seen = array_map(static fn (Capability $c): string => $c->id, $registry->all());
    }
}
