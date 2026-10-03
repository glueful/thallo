<?php

declare(strict_types=1);

namespace Thallo\Contracts\Extensions;

/**
 * Serializes changes to the enabled-provider list. A writer that rewrites the list and rebuilds
 * the extension cache runs the whole sequence inside within(), so two writers can't each read the
 * old list and drop the other's edit. Packages resolve it softly: without a binding they run
 * unlocked.
 */
interface ExtensionStateCoordinator
{
    /**
     * Runs $sequence holding the extension-state lock and returns its result.
     *
     * @template T
     * @param callable(): T $sequence
     * @return T
     */
    public function within(callable $sequence): mixed;
}
