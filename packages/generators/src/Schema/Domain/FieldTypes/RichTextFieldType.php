<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Dto\RichTextOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldValues;
use Cbox\Cms\Generators\Schema\Domain\RichTextLink;
use Cbox\Cms\Generators\Schema\Domain\RichTextList;
use Cbox\Cms\Generators\Schema\Domain\RichTextMark;
use Cbox\Cms\Generators\Schema\Domain\RichTextStyle;
use Override;

/**
 * The core field type `rich_text` in Portable Text (PRD 11.10), with the styles, marks, lists and
 * links it allows.
 */
#[Internal]
final readonly class RichTextFieldType implements FieldType
{
    public const string NAME = 'rich_text';

    #[Override]
    public function name(): string
    {
        return self::NAME;
    }

    #[Override]
    public function optionKeys(): array
    {
        return ['styles', 'marks', 'lists', 'links'];
    }

    #[Override]
    public function options(FieldValues $field): RichTextOptions
    {
        return new RichTextOptions(
            $field->enumList(RichTextStyle::class, 'styles'),
            $field->enumList(RichTextMark::class, 'marks'),
            $field->enumList(RichTextList::class, 'lists'),
            $field->enumList(RichTextLink::class, 'links'),
        );
    }
}
