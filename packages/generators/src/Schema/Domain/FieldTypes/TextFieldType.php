<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Dto\TextOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldValues;
use Cbox\Cms\Generators\Schema\Domain\TextFormat;
use Override;

/**
 * The core field type `text`: one line of text.
 */
#[Internal]
final readonly class TextFieldType implements FieldType
{
    public const string NAME = 'text';

    #[Override]
    public function name(): string
    {
        return self::NAME;
    }

    #[Override]
    public function optionKeys(): array
    {
        return ['min_length', 'max_length', 'format'];
    }

    #[Override]
    public function options(FieldValues $field): TextOptions
    {
        return new TextOptions(
            $field->optionalInt('min_length'),
            $field->optionalInt('max_length') ?? TextOptions::DEFAULT_MAX_LENGTH,
            $field->optionalEnum(TextFormat::class, 'format') ?? TextOptions::DEFAULT_FORMAT,
        );
    }
}
