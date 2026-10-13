<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Schema\InvalidTypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Generators\Schema\Domain\Dto\RelationOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldValues;
use Override;

/**
 * The core field type `relation` (PRD 5.11, 11.6): a reference to one other entry, or with
 * `multiple` to a list of them, whose types are among `types`.
 */
#[Internal]
final readonly class RelationFieldType implements FieldType
{
    public const string NAME = 'relation';

    #[Override]
    public function name(): string
    {
        return self::NAME;
    }

    #[Override]
    public function optionKeys(): array
    {
        return ['types', 'multiple', 'max_items'];
    }

    #[Override]
    public function options(FieldValues $field): ?RelationOptions
    {
        $names = $field->stringList('types');

        if ($names === null) {
            return null;
        }

        $types = [];

        foreach ($names as $name) {
            try {
                $types[] = new TypeName($name);
            } catch (InvalidTypeDefinition) {
                $field->invalid('types', sprintf('"%s" is not the name of a type, <owner>:<handle>', $name));

                return null;
            }
        }

        if ($types === []) {
            $field->invalid('types', 'a relation names at least one type');

            return null;
        }

        $multiple = $field->bool('multiple', RelationOptions::DEFAULT_MULTIPLE);

        return new RelationOptions(
            $types,
            $multiple,
            $multiple ? $field->optionalInt('max_items') ?? RelationOptions::MAX_ITEMS : null,
        );
    }
}
