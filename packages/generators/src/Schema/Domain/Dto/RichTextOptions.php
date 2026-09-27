<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
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
}
