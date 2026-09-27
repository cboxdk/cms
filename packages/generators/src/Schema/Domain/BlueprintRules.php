<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Schema\Domain\Dto\Blueprints;
use Cbox\Cms\Generators\Schema\Domain\Dto\ExtensionBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;

/**
 * The rules of the blueprint schema v1 that JSON Schema cannot express, because they compare
 * values with each other, within a file or across files and owners (blueprint proposal, "Regler
 * som JSON Schema ikke kan udtrykke"; PRD 11.12, 13.3). Each rule has its own error code and names
 * the file and the JSON pointer of the value that breaks it; where the rule compares two places,
 * the later one in file order is reported and names the earlier one.
 *
 * - A type_id belongs to one type across every owner, and a handle to one type of each owner. The
 *   same handle under two owners is allowed here; the generators decide what it may become.
 * - A handle belongs to one field in each namespace: a type's own fields, the fields one owner adds
 *   to one type across all its extension files, and the nested fields of each field, such as the
 *   fields of a group.
 * - A field type's own rules, which the options of each field carry (FieldOptions::problems()):
 *   a value belongs to one option of a select field; `min` is at most `max`, `min_length` at most
 *   `max_length`, `min_items` at most `max_items`, and a decimal's `scale` at most its `precision`.
 * - An extension extends a type that a blueprint file defines, and one that another owner owns: a
 *   type has one owner and only others extend it (PRD 11.12, 13.3).
 * - A column name has at most 63 bytes, the extension field's `ext__<namespace>__<handle>` too.
 *
 * The rules run on the blueprints that were read. When a file could not be read, the type it
 * defines is unknown, so an unknown `extends` is reported only when every file was read; the other
 * rules hold for any subset of the files and are always checked.
 */
#[Internal]
final readonly class BlueprintRules
{
    /**
     * @param  bool  $complete  whether every blueprint file below the schema roots was read into the blueprints
     * @return list<GenerationProblem>
     */
    public function check(Blueprints $blueprints, bool $complete): array
    {
        $problems = [];
        $this->types($blueprints->types, $problems);
        $this->extensions($blueprints, $complete, $problems);

        return $problems;
    }

    /**
     * @param  list<TypeBlueprint>  $types
     * @param  list<GenerationProblem>  $problems
     */
    private function types(array $types, array &$problems): void
    {
        /** @var array<string, TypeBlueprint> $byId */
        $byId = [];
        /** @var array<string, TypeBlueprint> $byHandle */
        $byHandle = [];

        foreach ($types as $type) {
            $id = $type->typeId->toString();
            $handle = $type->owner->value.'/'.$type->handle->value;

            if (array_key_exists($id, $byId)) {
                $problems[] = $this->problem(GenerateErrorCode::DuplicateTypeId, $type->location->below('type_id'), sprintf(
                    'the type_id %s is already the type_id of the type in %s. Every type needs its own type_id: give one of them a new UUIDv7.',
                    $id,
                    $byId[$id]->location->file,
                ));
            } else {
                $byId[$id] = $type;
            }

            if (array_key_exists($handle, $byHandle)) {
                $problems[] = $this->problem(GenerateErrorCode::DuplicateTypeHandle, $type->location->below('handle'), sprintf(
                    'the handle %s is already the handle of the type in %s. The types of %s need different handles.',
                    $type->handle->value,
                    $byHandle[$handle]->location->file,
                    $type->owner->value,
                ));
            } else {
                $byHandle[$handle] = $type;
            }

            $seen = [];
            $this->fields($type->fields, sprintf('the type %s', $type->handle->value), $seen, $problems);

            foreach ($type->fields as $field) {
                $this->columnName(ColumnName::ofTypeField($field->handle), $field, $problems);
            }
        }
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function extensions(Blueprints $blueprints, bool $complete, array &$problems): void
    {
        /** @var array<string, TypeBlueprint> $known the types read, by type_id; the first of a duplicate type_id */
        $known = [];

        foreach ($blueprints->types as $type) {
            $known[$type->typeId->toString()] ??= $type;
        }

        /** @var array<string, array<string, FieldBlueprint>> $namespaces the fields seen by extended type and extender, by handle */
        $namespaces = [];

        foreach ($blueprints->extensions as $extension) {
            $extends = $extension->extends->toString();
            $target = $known[$extends] ?? null;

            if ($target === null && $complete) {
                $problems[] = $this->problem(GenerateErrorCode::UnknownExtendsTarget, $extension->location->below('extends'), sprintf(
                    'no blueprint file below the schema roots defines a type with the type_id %s. Extend the type_id of an existing type, or add the schema root of the owner of the type.',
                    $extends,
                ));
            }

            if ($target !== null && $extension->owner->equals($target->owner)) {
                $problems[] = $this->problem(GenerateErrorCode::ExtensionOfOwnType, $extension->location->below('extends'), sprintf(
                    'the type_id %s is the type %s in %s, which %s owns. A type has one owner and only others extend it, so an owner adds fields to its own type in the type file: add the fields to %s.',
                    $extends,
                    $target->handle->value,
                    $target->location->file,
                    $target->owner->value,
                    $target->location->file,
                ));
            }

            $namespace = $extends.'/'.$extension->owner->value;
            $namespaces[$namespace] ??= [];
            $this->fields($extension->fields, $this->extensionNamespace($extension), $namespaces[$namespace], $problems);

            foreach ($extension->fields as $field) {
                $this->columnName(ColumnName::ofExtensionField($extension->owner, $field->handle), $field, $problems);
            }
        }
    }

    private function extensionNamespace(ExtensionBlueprint $extension): string
    {
        return sprintf('the fields %s adds to the type %s', $extension->owner->value, $extension->extends->toString());
    }

    /**
     * Checks one list of fields in a namespace, and the fields of each group in it as a namespace of
     * its own.
     *
     * @param  list<FieldBlueprint>  $fields
     * @param  string  $namespace  the namespace as a problem names it, such as "the type article"
     * @param  array<string, FieldBlueprint>  $seen  the fields of the namespace read before, by handle
     * @param  list<GenerationProblem>  $problems
     */
    private function fields(array $fields, string $namespace, array &$seen, array &$problems): void
    {
        foreach ($fields as $field) {
            $handle = $field->handle->value;

            if (array_key_exists($handle, $seen)) {
                $problems[] = $this->problem(GenerateErrorCode::DuplicateFieldHandle, $field->location->below('handle'), sprintf(
                    'the handle %s is already the handle of the field at %s. The fields of %s need different handles.',
                    $handle,
                    $seen[$handle]->location->describe(),
                    $namespace,
                ));
            } else {
                $seen[$handle] = $field;
            }

            $this->options($field, $problems);
        }
    }

    /**
     * The rules of the field's type, which its options carry, and the fields nested in it as a
     * namespace of their own, such as the fields of a group.
     *
     * @param  list<GenerationProblem>  $problems
     */
    private function options(FieldBlueprint $field, array &$problems): void
    {
        $seen = [];
        $this->fields($field->options->nestedFields(), sprintf('the %s %s at %s', $field->options->typeName(), $field->handle->value, $field->location->describe()), $seen, $problems);
        array_push($problems, ...$field->options->problems($field->location));
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function columnName(ColumnName $column, FieldBlueprint $field, array &$problems): void
    {
        if ($column->fits()) {
            return;
        }

        $problems[] = $this->problem(GenerateErrorCode::ColumnNameTooLong, $field->location->below('handle'), sprintf(
            'the column name %s has %d bytes, and Postgres allows at most %d. Shorten the handle by %d characters.',
            $column->value,
            $column->bytes(),
            ColumnName::MAX_BYTES,
            $column->bytes() - ColumnName::MAX_BYTES,
        ));
    }

    private function problem(GenerateErrorCode $code, SourceLocation $at, string $what): GenerationProblem
    {
        return new GenerationProblem($code, sprintf('%s: %s', $at->describe(), $what));
    }
}
