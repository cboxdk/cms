<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\Dto\ResolvedField;
use Cbox\Cms\Generators\Generation\Domain\Dto\ResolvedSchema;
use Cbox\Cms\Generators\Generation\Domain\Dto\ResolvedType;
use Cbox\Cms\Generators\Schema\Domain\ColumnName;
use Cbox\Cms\Generators\Schema\Domain\Dto\Blueprints;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;

/**
 * Applies the extensions of all schema roots to the types they extend and checks that the generated
 * code can name every type (PRD 11.12): a type handle is used once across owners
 * (generate_handle_collision), because the generated code names a type by its handle alone. The
 * blueprint reader's BlueprintRules allow the same handle for two owners.
 *
 * The resolver takes any Blueprints, so it does not rely on the reader for what it needs to build
 * the model, and refuses with the reader's codes what the reader's BlueprintRules already refuse:
 *
 * - a type_id is defined by one file (generate_duplicate_type_id);
 * - a type handle is used once by its owner (generate_duplicate_type_handle);
 * - an extension extends a type that a schema root defines (generate_unknown_extends_target);
 * - a field name is used once in its type: a handle once among the owner's fields, and an
 *   extension field once in its namespace (generate_duplicate_field_handle);
 * - the column name of an extension field, `ext__<namespace>__<handle>`, has at most 63 bytes, the
 *   Postgres limit, and is never cut short (generate_column_name_too_long).
 *
 * Every problem is collected before resolve() fails, so one run shows all of them.
 */
#[Internal]
final readonly class SchemaResolver
{
    /**
     * @throws GenerationFailed
     */
    public static function resolve(Blueprints $blueprints): ResolvedSchema
    {
        $problems = [];

        $byId = [];
        $byHandle = [];

        foreach ($blueprints->types as $type) {
            $id = $type->typeId->toString();
            $handle = $type->handle->value;

            if (isset($byId[$id])) {
                $problems[] = new GenerationProblem(GenerateErrorCode::DuplicateTypeId, sprintf(
                    '%s and %s both define the type_id %s. A type_id names one type: give one of them a new UUIDv7.',
                    $byId[$id]->location->file,
                    $type->location->file,
                    $id,
                ));
            } else {
                $byId[$id] = $type;
            }

            if (! isset($byHandle[$handle])) {
                $byHandle[$handle] = $type;

                continue;
            }

            $first = $byHandle[$handle];

            $problems[] = $first->owner->equals($type->owner)
                ? new GenerationProblem(GenerateErrorCode::DuplicateTypeHandle, sprintf(
                    '%s and %s both define a type "%s" of %s. A type handle is unique for its owner.',
                    $first->location->file,
                    $type->location->file,
                    $handle,
                    $type->owner->value,
                ))
                : new GenerationProblem(GenerateErrorCode::HandleCollision, sprintf(
                    'The type "%s" of %s (%s) and the type "%s" of %s (%s) have the same handle. cms:generate names a type by its handle alone, so the handles of all owners must differ: rename one of the types.',
                    $handle,
                    $first->owner->value,
                    $first->location->file,
                    $handle,
                    $type->owner->value,
                    $type->location->file,
                ));
        }

        // The fields of each type, by type_id and then by name.
        $fields = [];

        foreach ($byId as $id => $type) {
            $fields[$id] = [];

            foreach ($type->fields as $field) {
                self::add($fields[$id], ResolvedField::own($field), $type, $problems);
            }
        }

        foreach ($blueprints->extensions as $extension) {
            $id = $extension->extends->toString();

            if (! isset($byId[$id])) {
                $problems[] = new GenerationProblem(GenerateErrorCode::UnknownExtendsTarget, sprintf(
                    '%s extends the type_id %s, which no schema root defines. Check the type_id, or add the schema root of the type\'s owner.',
                    $extension->location->below('extends')->describe(),
                    $id,
                ));

                continue;
            }

            foreach ($extension->fields as $field) {
                $resolved = ResolvedField::extension($field);

                if (strlen($resolved->name) > ColumnName::MAX_BYTES) {
                    $problems[] = new GenerationProblem(GenerateErrorCode::ColumnNameTooLong, sprintf(
                        '%s: the column name %s has %d bytes, and Postgres allows %d. Choose a shorter handle.',
                        $field->location->describe(),
                        $resolved->name,
                        strlen($resolved->name),
                        ColumnName::MAX_BYTES,
                    ));

                    continue;
                }

                self::add($fields[$id], $resolved, $byId[$id], $problems);
            }
        }

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        ksort($byHandle, SORT_STRING);

        return new ResolvedSchema(array_values(array_map(
            static function (TypeBlueprint $type) use ($fields): ResolvedType {
                $typeFields = $fields[$type->typeId->toString()];
                ksort($typeFields, SORT_STRING);

                return new ResolvedType($type, array_values($typeFields));
            },
            $byHandle,
        )));
    }

    /**
     * @param  array<string, ResolvedField>  $fields
     * @param  list<GenerationProblem>  $problems
     */
    private static function add(array &$fields, ResolvedField $field, TypeBlueprint $type, array &$problems): void
    {
        if (! isset($fields[$field->name])) {
            $fields[$field->name] = $field;

            return;
        }

        $problems[] = new GenerationProblem(GenerateErrorCode::DuplicateFieldHandle, sprintf(
            '%s and %s both give the type "%s" the field %s. A field name is unique within its type.',
            $fields[$field->name]->blueprint->location->describe(),
            $field->blueprint->location->describe(),
            $type->handle->value,
            $field->name,
        ));
    }
}
