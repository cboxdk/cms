<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Dto\BooleanOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldValues;
use Override;

/**
 * The core field type `boolean`, which has no choices of its own.
 */
#[Internal]
final readonly class BooleanFieldType implements FieldType
{
    public const string NAME = 'boolean';

    #[Override]
    public function name(): string
    {
        return self::NAME;
    }

    #[Override]
    public function optionKeys(): array
    {
        return [];
    }

    #[Override]
    public function options(FieldValues $field): BooleanOptions
    {
        return new BooleanOptions;
    }
}
