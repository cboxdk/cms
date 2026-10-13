<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Schema\InvalidTypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\RelationOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldValues;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Override;

/**
 * The core field type `relation` (PRD 5.11, 11.6): a reference to one other entry, or with
 * `multiple` to a list of them, whose types are among `types`. With `meta`, each target carries the
 * attributes those fields declare, and a filterable relation names its `sort_by` field.
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
        return ['types', 'multiple', 'max_items', 'meta', 'sort_by'];
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
        $meta = [];

        if ($field->has('meta')) {
            $meta = $field->fields('meta');

            if ($meta === null) {
                return null;
            }
        }

        $sortBy = null;

        if ($field->has('sort_by')) {
            $sortBy = $field->handle('sort_by');

            if (! $sortBy instanceof Handle) {
                return null;
            }
        }

        return new RelationOptions(
            $types,
            $multiple,
            $multiple ? $field->optionalInt('max_items') ?? RelationOptions::MAX_ITEMS : null,
            $meta === [] ? null : $this->entry($types, $meta[0], $field->at()),
            $meta,
            $sortBy,
        );
    }

    /**
     * The nested field that holds a target's entry beside the attributes of `meta`: required, a
     * relation to one entry of the same types, of the owner and seen by agents as the attributes
     * are, which the reader gave every field nested in the relation, and declared where the relation
     * is, so a problem with its types names the relation's own keys.
     *
     * @param  non-empty-list<TypeName>  $types
     */
    private function entry(array $types, FieldBlueprint $attribute, SourceLocation $at): FieldBlueprint
    {
        return new FieldBlueprint(
            new Handle(RelationOptions::ENTRY),
            RelationOptions::ENTRY_LABEL,
            RelationOptions::ENTRY_DESCRIPTION,
            true,
            null,
            false,
            false,
            $attribute->agents,
            new RelationOptions($types, false, null),
            $attribute->owner,
            $at,
        );
    }
}
