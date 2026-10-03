<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What a replaceable point is keyed by, so that a replacement names the one target it takes the
 * place of: a field type (`<namespace>:<handle>` for an addon's own), a bound value class, or a
 * command.
 */
#[Experimental]
enum ReplacementKey: string
{
    case FieldType = 'field_type';
    case ValueClass = 'value_class';
    case Command = 'command';
}
