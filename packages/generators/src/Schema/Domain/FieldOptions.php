<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The type of a field and the choices that belong to it, such as `max_length` for `text`. Each
 * core field type has its own class in Dto, and an addon's field type is AddonOptions.
 */
#[Internal]
interface FieldOptions
{
    /**
     * The field type as the blueprint file writes it: `text`, or `acme:colour` for an addon's.
     */
    public function typeName(): string;
}
