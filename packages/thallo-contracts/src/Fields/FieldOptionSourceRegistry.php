<?php

declare(strict_types=1);

namespace Thallo\Contracts\Fields;

interface FieldOptionSourceRegistry
{
    /** @throws \LogicException on a duplicate id */
    public function register(FieldOptionSource $source): void;

    public function find(string $id): ?FieldOptionSource;
}
