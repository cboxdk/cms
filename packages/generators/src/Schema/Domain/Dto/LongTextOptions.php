<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\LongTextFieldType;
use Cbox\Cms\Generators\Schema\Domain\OptionRules;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
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
        return LongTextFieldType::NAME;
    }

    #[Override]
    public function problems(SourceLocation $field): array
    {
        return OptionRules::lengths($this->minLength, $this->maxLength, self::DEFAULT_MAX_LENGTH, $field);
    }

    #[Override]
    public function nestedFields(): array
    {
        return [];
    }
}
