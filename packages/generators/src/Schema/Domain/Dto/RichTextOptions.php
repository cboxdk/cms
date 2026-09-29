<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ColumnShape;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\PhpType;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeScriptType;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValueShape;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\RichTextFieldType;
use Cbox\Cms\Generators\Schema\Domain\RichTextLink;
use Cbox\Cms\Generators\Schema\Domain\RichTextList;
use Cbox\Cms\Generators\Schema\Domain\RichTextMark;
use Cbox\Cms\Generators\Schema\Domain\RichTextStyle;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Override;

/**
 * A `rich_text` field in Portable Text (PRD 11.10), with the styles, marks, lists and links it
 * allows. A list the file leaves out is null, kept apart from an empty list.
 */
#[Internal]
final readonly class RichTextOptions implements FieldOptions
{
    /**
     * @param  ?list<RichTextStyle>  $styles
     * @param  ?list<RichTextMark>  $marks
     * @param  ?list<RichTextList>  $lists
     * @param  ?list<RichTextLink>  $links
     */
    public function __construct(
        public ?array $styles,
        public ?array $marks,
        public ?array $lists,
        public ?array $links,
    ) {}

    #[Override]
    public function typeName(): string
    {
        return RichTextFieldType::NAME;
    }

    #[Override]
    public function problems(SourceLocation $field): array
    {
        return [];
    }

    #[Override]
    public function nestedFields(): array
    {
        return [];
    }

    #[Override]
    public function describeColumn(string $column): ColumnShape
    {
        return new ColumnShape('jsonb', [sprintf("jsonb_typeof(%s) = 'array'", $column)]);
    }

    /**
     * Portable Text blocks, and the styles, marks, lists and links they may use: those the file
     * lists, or every one of the blueprint schema v1 when it leaves the list out.
     */
    #[Override]
    public function describeValue(array $fields): ValueShape
    {
        return new ValueShape(
            new PhpType('array', 'list<array<string, mixed>>'),
            new TypeScriptType('Array<Record<string, unknown>>'),
            [
                new ValidationRule(ValidationRuleName::PortableText),
                new ValidationRule(ValidationRuleName::Styles, $this->values($this->styles ?? RichTextStyle::cases())),
                new ValidationRule(ValidationRuleName::Marks, $this->values($this->marks ?? RichTextMark::cases())),
                new ValidationRule(ValidationRuleName::Lists, $this->values($this->lists ?? RichTextList::cases())),
                new ValidationRule(ValidationRuleName::Links, $this->values($this->links ?? RichTextLink::cases())),
            ],
        );
    }

    /**
     * @param  list<RichTextStyle|RichTextMark|RichTextList|RichTextLink>  $cases
     * @return list<string>
     */
    private function values(array $cases): array
    {
        return array_map(static fn (RichTextStyle|RichTextMark|RichTextList|RichTextLink $case): string => $case->value, $cases);
    }
}
