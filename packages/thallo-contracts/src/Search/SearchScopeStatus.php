<?php

declare(strict_types=1);

namespace Thallo\Contracts\Search;

/** Whether a Search block's scope can be searched now, and if not, why (search block spec §3.1). */
interface SearchScopeStatus
{
    /**
     * @param string $scope '' for every kind
     * @return array{available: bool, label: ?string, reason: ?string}
     */
    public function stateOf(string $scope): array;
}
