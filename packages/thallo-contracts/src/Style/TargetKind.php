<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/** The layout kind of a named style target (spec §1.7); capability-to-target rules key on it. */
enum TargetKind: string
{
    case Text = 'text';
    case Row = 'row';
    case Stack = 'stack';
    case Box = 'box';
}
