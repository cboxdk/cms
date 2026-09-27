<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use LogicException;

/**
 * Two field types with the same name were registered. The registry is wired in code, so this is a
 * wiring error and never the fault of a blueprint file.
 */
#[Internal]
final class DuplicateFieldType extends LogicException
{
    public static function named(string $name, FieldTypeContributor $first, FieldTypeContributor $second): self
    {
        return new self(sprintf(
            'The field type "%s" is registered by both %s and %s. A field type has one name, so each name may be registered once.',
            $name,
            $first::class,
            $second::class,
        ));
    }
}
