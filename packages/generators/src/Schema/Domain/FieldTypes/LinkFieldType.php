<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\LinkKind;
use Cbox\Cms\Generators\Schema\Domain\Dto\LinkOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldValues;
use Override;

/**
 * The core field type `link` (B2-T76): one target, an entry with an optional locale, a node of the
 * structure or an absolute http or https URL with its rels, of the `kinds` the field allows.
 */
#[Internal]
final readonly class LinkFieldType implements FieldType
{
    public const string NAME = 'link';

    #[Override]
    public function name(): string
    {
        return self::NAME;
    }

    #[Override]
    public function optionKeys(): array
    {
        return ['kinds'];
    }

    #[Override]
    public function options(FieldValues $field): LinkOptions
    {
        return new LinkOptions($field->enumList(LinkKind::class, 'kinds') ?? array_map(LinkKind::from(...), LinkOptions::DEFAULT_KINDS));
    }
}
