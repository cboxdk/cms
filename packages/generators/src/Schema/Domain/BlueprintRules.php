<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Schema\Domain\Dto\AddonOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\Blueprints;
use Cbox\Cms\Generators\Schema\Domain\Dto\DateOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DatetimeOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DecimalOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\ExtensionBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\GroupOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\GroupRepeat;
use Cbox\Cms\Generators\Schema\Domain\Dto\IntegerOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\LongTextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TextOptions;
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
 *   to one type across all its extension files, and the fields of each group.
 * - A value belongs to one option of a select field.
 * - An extension extends a type that a blueprint file defines.
 * - A column name has at most 63 bytes, the extension field's `ext__<namespace>__<handle>` too.
 * - `min` is at most `max`, `min_length` at most `max_length`, `min_items` at most `max_items`,
 *   and a decimal's `scale` at most its `precision`.
 * - An addon field type `<namespace>:<handle>` is one that a registered contributor provides.
 *
 * The rules run on the blueprints that were read. When a file could not be read, the type it
 * defines is unknown, so an unknown `extends` is reported only when every file was read; the other
 * rules hold for any subset of the files and are always checked.
 */
#[Internal]
final readonly class BlueprintRules
{
    public function __construct(private ContributedFieldTypes $fieldTypes) {}

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
        $known = [];

        foreach ($blueprints->types as $type) {
            $known[$type->typeId->toString()] = true;
        }

        /** @var array<string, array<string, FieldBlueprint>> $namespaces the fields seen by extended type and extender, by handle */
        $namespaces = [];

        foreach ($blueprints->extensions as $extension) {
            $extends = $extension->extends->toString();

            if ($complete && ! array_key_exists($extends, $known)) {
                $problems[] = $this->problem(GenerateErrorCode::UnknownExtendsTarget, $extension->location->below('extends'), sprintf(
                    'no blueprint file below the schema roots defines a type with the type_id %s. Extend the type_id of an existing type, or add the schema root of the owner of the type.',
                    $extends,
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
     * @param  list<GenerationProblem>  $problems
     */
    private function options(FieldBlueprint $field, array &$problems): void
    {
        $options = $field->options;
        $at = $field->location;

        if ($options instanceof TextOptions) {
            $this->lengths($options->minLength, $options->maxLength, TextOptions::DEFAULT_MAX_LENGTH, $at, $problems);
        } elseif ($options instanceof LongTextOptions) {
            $this->lengths($options->minLength, $options->maxLength, LongTextOptions::DEFAULT_MAX_LENGTH, $at, $problems);
        } elseif ($options instanceof IntegerOptions && $options->min !== null && $options->max !== null) {
            $this->range($options->min <=> $options->max, (string) $options->min, (string) $options->max, $at, $problems);
        } elseif ($options instanceof DecimalOptions) {
            $this->decimal($options, $at, $problems);
        } elseif ($options instanceof DateOptions && $options->min !== null && $options->max !== null) {
            $this->range(Bounds::compareDates($options->min, $options->max), $options->min, $options->max, $at, $problems);
        } elseif ($options instanceof DatetimeOptions && $options->min !== null && $options->max !== null) {
            $this->range(Bounds::compareDatetimes($options->min, $options->max), $options->min, $options->max, $at, $problems);
        } elseif ($options instanceof SelectOptions) {
            $this->select($options, $at, $problems);
        } elseif ($options instanceof GroupOptions) {
            $this->group($field, $options, $problems);
        } elseif ($options instanceof AddonOptions) {
            $this->addonType($options->type, $at, $problems);
        }
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function lengths(?int $minLength, int $maxLength, int $defaultMaxLength, SourceLocation $at, array &$problems): void
    {
        if ($minLength === null || $minLength <= $maxLength) {
            return;
        }

        $problems[] = $this->problem(GenerateErrorCode::MinLengthAboveMaxLength, $at->below('min_length'), sprintf(
            'min_length %d is greater than max_length %d, which is %d when the field leaves it out. Make min_length at most max_length.',
            $minLength,
            $maxLength,
            $defaultMaxLength,
        ));
    }

    /**
     * @param  ?int  $comparison  min compared with max, or null when a bound is not of its form
     * @param  list<GenerationProblem>  $problems
     */
    private function range(?int $comparison, string $min, string $max, SourceLocation $at, array &$problems): void
    {
        if ($comparison === null || $comparison <= 0) {
            return;
        }

        $problems[] = $this->problem(GenerateErrorCode::MinAboveMax, $at->below('min'), sprintf(
            'min %s is greater than max %s, so no value fits. Make min at most max.',
            $min,
            $max,
        ));
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function decimal(DecimalOptions $options, SourceLocation $at, array &$problems): void
    {
        if ($options->scale > $options->precision) {
            $problems[] = $this->problem(GenerateErrorCode::ScaleAbovePrecision, $at->below('scale'), sprintf(
                'scale %d is greater than precision %d. The precision is the number of digits and the scale the number of them after the decimal point, so make the scale at most the precision.',
                $options->scale,
                $options->precision,
            ));
        }

        if ($options->min !== null && $options->max !== null) {
            $this->range(Bounds::compareDecimals($options->min, $options->max), $options->min, $options->max, $at, $problems);
        }
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function select(SelectOptions $options, SourceLocation $at, array &$problems): void
    {
        $values = [];

        foreach ($options->options as $index => $option) {
            $value = $option->value->value;

            if (array_key_exists($value, $values)) {
                $problems[] = $this->problem(GenerateErrorCode::DuplicateSelectValue, $at->below('options', $index, 'value'), sprintf(
                    'the value %s is already the value of the option at %s. The options of a select field need different values.',
                    $value,
                    $at->below('options', $values[$value], 'value')->describe(),
                ));
            } else {
                $values[$value] = $index;
            }
        }

        $this->items($options->minItems, $options->maxItems, $at, $problems);
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function group(FieldBlueprint $field, GroupOptions $options, array &$problems): void
    {
        $seen = [];
        $this->fields($options->fields, sprintf('the group %s at %s', $field->handle->value, $field->location->describe()), $seen, $problems);

        if ($options->repeat instanceof GroupRepeat) {
            $this->items($options->repeat->minItems, $options->repeat->maxItems, $field->location->below('repeat'), $problems);
        }
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function items(?int $minItems, ?int $maxItems, SourceLocation $at, array &$problems): void
    {
        if ($minItems === null || $maxItems === null || $minItems <= $maxItems) {
            return;
        }

        $problems[] = $this->problem(GenerateErrorCode::MinItemsAboveMaxItems, $at->below('min_items'), sprintf(
            'min_items %d is greater than max_items %d, so no list of items fits. Make min_items at most max_items.',
            $minItems,
            $maxItems,
        ));
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function addonType(AddonFieldType $type, SourceLocation $at, array &$problems): void
    {
        if ($this->fieldTypes->provides($type)) {
            return;
        }

        $problems[] = $this->problem(GenerateErrorCode::UnknownFieldType, $at->below('type'), sprintf(
            'no registered contributor provides the field type %s. Install the addon of the namespace %s that contributes it, or use a core field type.',
            $type->value,
            $type->namespace,
        ));
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
