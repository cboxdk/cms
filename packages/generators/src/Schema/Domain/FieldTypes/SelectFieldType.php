<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOption;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldValues;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Override;

/**
 * The core field type `select`: one choice from a fixed list, or several.
 */
#[Internal]
final readonly class SelectFieldType implements FieldType
{
    public const string NAME = 'select';

    #[Override]
    public function name(): string
    {
        return self::NAME;
    }

    #[Override]
    public function optionKeys(): array
    {
        return ['options', 'multiple', 'min_items', 'max_items'];
    }

    #[Override]
    public function options(FieldValues $field): ?SelectOptions
    {
        $items = $field->objectList('options');

        if ($items === null) {
            return null;
        }

        $options = [];

        foreach ($items as $item) {
            $item->knownKeys(['value', 'label']);
            $value = $item->handle('value');
            $label = $item->string('label');

            if ($value instanceof Handle && $label !== null) {
                $options[] = new SelectOption($value, $label);
            }
        }

        if (count($options) !== count($items)) {
            return null;
        }

        return new SelectOptions(
            $options,
            $field->bool('multiple', SelectOptions::DEFAULT_MULTIPLE),
            $field->optionalInt('min_items'),
            $field->optionalInt('max_items'),
        );
    }
}
