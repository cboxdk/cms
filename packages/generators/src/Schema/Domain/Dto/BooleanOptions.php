<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\CoreFieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Override;

/**
 * A `boolean` field. It has no choices of its own.
 */
#[Internal]
final readonly class BooleanOptions implements FieldOptions
{
    #[Override]
    public function typeName(): string
    {
        return CoreFieldType::Boolean->value;
    }
}
