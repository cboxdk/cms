<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\FieldTypeContributor;
use Override;

/**
 * The core's own contribution to the FieldTypeRegistry: the ten field types of the blueprint schema
 * v1 (blueprint decision 1). The core registers them through the same interface as any other
 * contributor (GUARDRAILS 2.4), and the generator coverage test holds them to the core field types
 * that the installed blueprint.v1.json lists.
 */
#[Internal]
final readonly class CoreFieldTypes implements FieldTypeContributor
{
    #[Override]
    public function fieldTypes(): array
    {
        return [
            new TextFieldType,
            new LongTextFieldType,
            new IntegerFieldType,
            new DecimalFieldType,
            new BooleanFieldType,
            new DateFieldType,
            new DatetimeFieldType,
            new SelectFieldType,
            new RichTextFieldType,
            new GroupFieldType,
        ];
    }
}
