<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Schema\Domain\Dto\Blueprints;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;

/**
 * A type has at most 200 top-level fields (PRD 11.6), its own and every field that extensions add
 * to it together: each is a column of the type's table, an extension field as
 * `ext__<namespace>__<handle>` (PRD 11.12), so the limit keeps the table bounded. The blueprint
 * schema caps each file at 200 fields, and this rule caps the type once every extension file is
 * composed with it, whichever owners they come from and however many files one owner splits its
 * fields over.
 *
 * A field is counted once by its column, so a duplicate handle, which generate_duplicate_field_handle
 * reports, is not counted twice. Only an extension of a known type by another owner is counted:
 * an unknown `extends` and an extension of an owner's own type are refused with their own codes
 * and add no column. Of two types with one type_id, the first is counted, as the resolver keeps it.
 * On a subset of the files the count is a lower bound, so a type over the limit is over it with
 * every file too.
 */
#[Internal]
final readonly class TypeFieldLimit
{
    /** The most top-level fields of a type, its own and its extensions' together (PRD 11.6). */
    public const int MAX_FIELDS = 200;

    /**
     * One generate_too_many_fields problem for each type over the limit, at the type file's
     * `/fields`, naming the extension files that add to it.
     *
     * @return list<GenerationProblem>
     */
    public static function problems(Blueprints $blueprints): array
    {
        /** @var array<string, TypeBlueprint> $types the types by type_id; the first of a duplicate type_id */
        $types = [];
        /** @var array<string, array<string, true>> $own the columns of each type's own fields */
        $own = [];

        foreach ($blueprints->types as $type) {
            $id = $type->typeId->toString();

            if (isset($types[$id])) {
                continue;
            }

            $types[$id] = $type;
            $own[$id] = [];

            foreach ($type->fields as $field) {
                $own[$id][ColumnName::ofTypeField($field->handle)->value] = true;
            }
        }

        /** @var array<string, array<string, true>> $added the columns that extensions add to each type */
        $added = [];
        /** @var array<string, array<string, true>> $files the extension files that add to each type, in file order */
        $files = [];

        foreach ($blueprints->extensions as $extension) {
            $id = $extension->extends->toString();

            if (! isset($types[$id]) || $extension->owner->equals($types[$id]->owner)) {
                continue;
            }

            foreach ($extension->fields as $field) {
                $added[$id][ColumnName::ofExtensionField($extension->owner, $field->handle)->value] = true;
            }

            $files[$id][$extension->location->file] = true;
        }

        $problems = [];

        foreach ($types as $id => $type) {
            $ownCount = count($own[$id]);
            $addedCount = count($added[$id] ?? []);

            if ($ownCount + $addedCount <= self::MAX_FIELDS) {
                continue;
            }

            $problems[] = new GenerationProblem(GenerateErrorCode::TooManyFields, sprintf(
                '%s: the type %s of %s has %d fields, %s. A type has at most %d fields, its own and those its extensions add together, because each is a column of its table: remove fields, or model a part of the type as a type of its own.',
                $type->location->below('fields')->describe(),
                $type->handle->value,
                $type->owner->value,
                $ownCount + $addedCount,
                $addedCount === 0
                    ? 'all of its own'
                    : sprintf('%d of its own and %d that extensions add in %s', $ownCount, $addedCount, implode(', ', array_keys($files[$id] ?? []))),
                self::MAX_FIELDS,
            ));
        }

        return $problems;
    }
}
