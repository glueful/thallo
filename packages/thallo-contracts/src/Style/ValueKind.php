<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/**
 * The kinds a managed style value can take (visual builder spec §1.1). `Literal` is reserved:
 * the type grammar names it so a later schema version can admit it; validation rejects it.
 */
enum ValueKind: string
{
    case Token = 'token';
    case Choice = 'choice';
    case Identifier = 'identifier';
    case Reset = 'reset';
    case Literal = 'literal';
}
