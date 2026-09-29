<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ColumnShape;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\PhpType;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeScriptType;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValueShape;
use Cbox\Cms\Generators\Descriptor\Domain\ShapeParts;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\TextFieldType;
use Cbox\Cms\Generators\Schema\Domain\OptionRules;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
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
        return TextFieldType::NAME;
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

    #[Override]
    public function describeColumn(string $column): ColumnShape
    {
        return new ColumnShape('text', ShapeParts::lengthChecks($column, $this->minLength, $this->maxLength));
    }

    #[Override]
    public function describeValue(array $fields): ValueShape
    {
        $rules = [new ValidationRule(ValidationRuleName::String), ...ShapeParts::lengthRules($this->minLength, $this->maxLength)];

        if ($this->format !== TextFormat::Plain) {
            $rules[] = new ValidationRule(ValidationRuleName::Format, [$this->format->value]);
        }

        return new ValueShape(new PhpType('string', 'string'), new TypeScriptType('string'), $rules);
    }
}
