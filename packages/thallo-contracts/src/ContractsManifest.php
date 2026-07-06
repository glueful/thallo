<?php

declare(strict_types=1);

namespace Thallo\Contracts;

/**
 * Marker for the thallo-contracts package. Holds nothing but the package version —
 * proof the package is installed and autoloading. Real contracts live in subnamespaces.
 */
final class ContractsManifest
{
    public const VERSION = '0.1.0';
}
