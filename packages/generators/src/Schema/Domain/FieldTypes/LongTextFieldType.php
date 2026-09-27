<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Dto\LongTextOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldValues;
use Override;

/**
 * The core field type `long_text`: plain text over several lines.
 */
#[Internal]
final readonly class LongTextFieldType implements FieldType
{
    public const string NAME = 'long_text';

    #[Override]
    public function name(): string
    {
        return self::NAME;
    }

    #[Override]
    public function optionKeys(): array
    {
        return ['min_length', 'max_length'];
    }

    #[Override]
    public function options(FieldValues $field): LongTextOptions
    {
        return new LongTextOptions(
            $field->optionalInt('min_length'),
            $field->optionalInt('max_length') ?? LongTextOptions::DEFAULT_MAX_LENGTH,
        );
    }
}
