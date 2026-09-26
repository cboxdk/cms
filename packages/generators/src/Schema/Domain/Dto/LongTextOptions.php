<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\CoreFieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Override;

/**
 * A `long_text` field: plain text over several lines, of at most `max_length` characters (default
 * 10,000).
 */
#[Internal]
final readonly class LongTextOptions implements FieldOptions
{
    public const int DEFAULT_MAX_LENGTH = 10000;

    public function __construct(
        public ?int $minLength,
        public int $maxLength,
    ) {}

    #[Override]
    public function typeName(): string
    {
        return CoreFieldType::LongText->value;
    }
}
