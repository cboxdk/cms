<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\ExtensionVersion;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\Dto\ResolvedField;
use Cbox\Cms\Generators\Generation\Domain\Dto\ResolvedSchema;
use Cbox\Cms\Generators\Generation\Domain\Dto\ResolvedType;
use Cbox\Cms\Generators\Schema\Domain\ColumnName;
use Cbox\Cms\Generators\Schema\Domain\Dto\Blueprints;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;
use Cbox\Cms\Generators\Schema\Domain\TypeFieldLimit;

/**
 * Applies the extensions of all schema roots to the types they extend (PRD 11.12). Two owners may
 * each have a type with the same handle: the type_id is a type's identity (PRD 11.2), and the
 * generated code names a type by its owner and handle (ResolvedType::name()), so a module release
 * that adds a type the application already has is never a compile error mid-upgrade (PRD 11.12
 * point 2, 13.3).
 *
 * The resolver takes any Blueprints, so it does not rely on the reader for what it needs to build
 * the model, and refuses with the reader's codes what the reader's BlueprintRules already refuse:
 *
 * - a type_id is defined by one file (generate_duplicate_type_id);
 * - a type handle is used once by its owner (generate_duplicate_type_handle);
 * - an extension extends a type that a schema root defines (generate_unknown_extends_target);
 * - an extension extends a type of another owner, because a type has one owner and only others
 *   extend it (generate_extension_of_own_type);
 * - the extension files of one owner for one type declare the same version, the extender's part
 *   of the type's composite version (generate_extension_version_mismatch);
 * - a field name is used once in its type: a handle once among the owner's fields, and an
 *   extension field once in its namespace (generate_duplicate_field_handle);
 * - the column name of an extension field, `ext__<namespace>__<handle>`, has at most 63 bytes, the
 *   Postgres limit, and is never cut short (generate_column_name_too_long);
 * - a type has at most 200 top-level fields, its own and those every extension adds to it together,
 *   because each is a column of its table (generate_too_many_fields, TypeFieldLimit, PRD 11.6).
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
        $byName = [];

        foreach ($blueprints->types as $type) {
            $id = $type->typeId->toString();
            $name = ResolvedType::nameOf($type->owner, $type->handle);

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

            if (! isset($byName[$name])) {
                $byName[$name] = $type;

                continue;
            }

            $problems[] = new GenerationProblem(GenerateErrorCode::DuplicateTypeHandle, sprintf(
                '%s and %s both define a type "%s" of %s. A type handle is unique for its owner.',
                $byName[$name]->location->file,
                $type->location->file,
                $type->handle->value,
                $type->owner->value,
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

        // The first extension file of each extended type and extender, whose version the others must have.
        $firsts = [];

        foreach ($blueprints->extensions as $extension) {
            $id = $extension->extends->toString();
            $first = $firsts[$id.'/'.$extension->owner->value] ??= $extension;

            if ($first->version !== $extension->version) {
                $problems[] = new GenerationProblem(GenerateErrorCode::ExtensionVersionMismatch, sprintf(
                    '%s is the version %d of the fields %s adds to the type %s, and %s is the version %d. The fields one owner adds to one type have one version, its part of the type\'s composite version: give every extension file of %s for the type the same version.',
                    $extension->location->below('version')->describe(),
                    $extension->version,
                    $extension->owner->value,
                    isset($byId[$id]) ? '"'.$byId[$id]->handle->value.'"' : $id,
                    $first->location->below('version')->describe(),
                    $first->version,
                    $extension->owner->value,
                ));
            }

            if (! isset($byId[$id])) {
                $problems[] = new GenerationProblem(GenerateErrorCode::UnknownExtendsTarget, sprintf(
                    '%s extends the type_id %s, which no schema root defines. Check the type_id, or add the schema root of the type\'s owner.',
                    $extension->location->below('extends')->describe(),
                    $id,
                ));

                continue;
            }

            $target = $byId[$id];

            if ($extension->owner->equals($target->owner)) {
                $problems[] = new GenerationProblem(GenerateErrorCode::ExtensionOfOwnType, sprintf(
                    '%s extends the type "%s" in %s, which %s owns. An owner adds fields to its own type in the type file, not with an extension: add the fields to %s.',
                    $extension->location->below('extends')->describe(),
                    $target->handle->value,
                    $target->location->file,
                    $target->owner->value,
                    $target->location->file,
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

                self::add($fields[$id], $resolved, $target, $problems);
            }
        }

        array_push($problems, ...TypeFieldLimit::problems($blueprints));

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        ksort($byName, SORT_STRING);
        ksort($firsts, SORT_STRING);

        return new ResolvedSchema(array_values(array_map(
            static function (TypeBlueprint $type) use ($fields, $firsts): ResolvedType {
                $id = $type->typeId->toString();
                $typeFields = $fields[$id];
                ksort($typeFields, SORT_STRING);
                $extensions = [];

                foreach ($firsts as $first) {
                    if ($first->extends->toString() === $id) {
                        $extensions[] = new ExtensionVersion($first->owner, $first->version);
                    }
                }

                return new ResolvedType($type, array_values($typeFields), $extensions);
            },
            $byName,
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
