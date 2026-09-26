<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\CoreFieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\TextFormat;
use Override;

/**
 * A `text` field: one line of text of at most `max_length` characters (default 255), in a format.
 */
#[Internal]
final readonly class TextOptions implements FieldOptions
{
    public const int DEFAULT_MAX_LENGTH = 255;

    public const TextFormat DEFAULT_FORMAT = TextFormat::Plain;

    public function __construct(
        public ?int $minLength,
        public int $maxLength,
        public TextFormat $format,
    ) {}

    #[Override]
    public function typeName(): string
    {
        return CoreFieldType::Text->value;
    }
}
