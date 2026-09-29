<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Migrations\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ColumnDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeDescriptor;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Migrations\Domain\Dto\LockedColumn;
use Cbox\Cms\Generators\Migrations\Domain\Dto\TypeTableLock;

/**
 * The next schema lock of every type table, from the compiled schema and the committed locks
 * (PRD 11.6, 11.12).
 *
 * Until schema evolution (B3) a type table only grows, in two ways:
 *
 * - a type without a lock gets a new lock of one step: its table with the columns of all its
 *   top-level fields;
 * - a type whose fields include columns its lock lacks gets one more step that adds them, when each
 *   is nullable: an optional field of the owner, or any extension field (PRD 11.12, point 1).
 *
 * Every other difference is refused, each with its own code: a locked type that is gone
 * (generate_type_removed), another type_id, stages or localization (generate_table_changed), a
 * locked column that is gone (generate_field_removed) or differs (generate_field_changed), and a
 * new column that is NOT NULL (generate_required_field_added). A table name over 54 bytes is
 * refused with generate_table_name_too_long. Every problem is collected before it fails.
 */
#[Internal]
final readonly class TableChanges
{
    /**
     * @param  list<TypeTableLock>  $locks  the committed locks, each table once
     * @return list<TypeTableLock> the next lock of every type, sorted by table
     *
     * @throws GenerationFailed
     */
    public static function next(CompiledSchema $schema, array $locks): array
    {
        $byTable = [];

        foreach ($locks as $lock) {
            $byTable[$lock->table] = $lock;
        }

        $problems = [];
        $next = [];

        foreach ($schema->types as $type) {
            $table = TypeTableNames::table($type);

            if (! TypeTableNames::fits($table)) {
                $problems[] = new GenerationProblem(GenerateErrorCode::TableNameTooLong, sprintf(
                    '%s: the table of the type "%s" is %s, %d bytes, and a type table has at most %d, so the names of its policies fit in 63. Choose a shorter handle.',
                    $type->location->below('handle')->describe(),
                    $type->name(),
                    $table,
                    strlen($table),
                    TypeTableNames::MAX_TABLE_BYTES,
                ));

                continue;
            }

            $lock = $byTable[$table] ?? null;
            unset($byTable[$table]);
            $locked = $lock instanceof TypeTableLock ? self::grown($type, $lock, $problems) : self::created($type, $table);

            if ($locked instanceof TypeTableLock) {
                $next[$table] = $locked;
            }
        }

        foreach ($byTable as $table => $lock) {
            $problems[] = new GenerationProblem(GenerateErrorCode::TypeRemoved, sprintf(
                'The type "%s" has the table %s in the schema lock %s, but no blueprint defines it. A type table is never removed or renamed until schema evolution comes (B3): put the type back.',
                $lock->type->value,
                $table,
                $lock->file(),
            ));
        }

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        ksort($next, SORT_STRING);

        return array_values($next);
    }

    private static function created(TypeDescriptor $type, string $table): TypeTableLock
    {
        return new TypeTableLock(
            $table,
            new TypeName($type->name()),
            $type->typeId,
            $type->capabilities->stages,
            $type->capabilities->localization,
            1,
            self::columns($type, 1),
        );
    }

    /**
     * The lock with one more step for the type's new columns, the same lock when there are none,
     * or null with the problems that refuse the change.
     *
     * @param  list<GenerationProblem>  $problems
     */
    private static function grown(TypeDescriptor $type, TypeTableLock $lock, array &$problems): ?TypeTableLock
    {
        $found = [];
        $capabilities = $type->capabilities;

        // The system columns' checks decide whether the table changes, so a value that gives the
        // same table, such as another localization with the same variants, is no change.
        if (! $type->typeId->equals($lock->typeId)
            || TypeTableDdl::stageCheck($capabilities->stages) !== TypeTableDdl::stageCheck($lock->stages)
            || TypeTableDdl::localeCheck($capabilities->localization) !== TypeTableDdl::localeCheck($lock->localization)) {
            $found[] = new GenerationProblem(GenerateErrorCode::TableChanged, sprintf(
                '%s: the type "%s" has type_id %s, stages %s and localization %s, and its schema lock %s has type_id %s, stages %s and localization %s. Its table keeps its key and system columns until schema evolution comes (B3): undo the change, or define a new type.',
                $type->location->describe(),
                $type->name(),
                $type->typeId->toString(),
                $capabilities->stages->value,
                $capabilities->localization->value,
                $lock->file(),
                $lock->typeId->toString(),
                $lock->stages->value,
                $lock->localization->value,
            ));
        }

        $step = $lock->steps + 1;
        $added = [];
        $present = [];

        foreach ($type->fields as $field) {
            if (! $field->column instanceof ColumnDescriptor) {
                continue;
            }

            $column = self::column($field, $field->column, $step);
            $present[$column->name] = true;
            $existing = $lock->column($column->name);

            if ($existing instanceof LockedColumn) {
                if (! $existing->sameShape($column)) {
                    $found[] = new GenerationProblem(GenerateErrorCode::FieldChanged, sprintf(
                        '%s: the column %s of %s is %s, and its schema lock %s has %s. An existing column does not change until schema evolution comes (B3): undo the change, or add a new optional field instead.',
                        $field->location->describe(),
                        $column->name,
                        $lock->table,
                        self::describe($column),
                        $lock->file(),
                        self::describe($existing),
                    ));
                }

                continue;
            }

            if ($column->notNull) {
                $found[] = new GenerationProblem(GenerateErrorCode::RequiredFieldAdded, sprintf(
                    '%s: the new field %s of %s is required, so its column would be NOT NULL on the rows that exist. Until schema evolution comes (B3), a field added to a type with a table is optional: drop `required`.',
                    $field->location->describe(),
                    $column->name,
                    $type->name(),
                ));

                continue;
            }

            $added[] = $column;
        }

        foreach ($lock->columns as $existing) {
            if (! isset($present[$existing->name])) {
                $found[] = new GenerationProblem(GenerateErrorCode::FieldRemoved, sprintf(
                    '%s: the type "%s" has no field for the column %s, which its schema lock %s has since step %d. A column is never removed or renamed until schema evolution comes (B3): put the field back.',
                    $type->location->describe(),
                    $type->name(),
                    $existing->name,
                    $lock->file(),
                    $existing->step,
                ));
            }
        }

        if ($found !== []) {
            array_push($problems, ...$found);

            return null;
        }

        if ($added === []) {
            return $lock;
        }

        return new TypeTableLock($lock->table, $lock->type, $lock->typeId, $lock->stages, $lock->localization, $step, [...$lock->columns, ...$added]);
    }

    /**
     * The columns of the type's top-level fields, sorted by name as the descriptor sorts them.
     *
     * @return list<LockedColumn>
     */
    private static function columns(TypeDescriptor $type, int $step): array
    {
        $columns = [];

        foreach ($type->fields as $field) {
            if ($field->column instanceof ColumnDescriptor) {
                $columns[] = self::column($field, $field->column, $step);
            }
        }

        return $columns;
    }

    private static function column(FieldDescriptor $field, ColumnDescriptor $column, int $step): LockedColumn
    {
        return new LockedColumn($column->name, $column->type, $column->notNull, $column->checks, $field->filterable || $field->sortable, $step);
    }

    private static function describe(LockedColumn $column): string
    {
        return sprintf(
            '%s%s with %s and %s',
            $column->type,
            $column->notNull ? ' not null' : '',
            $column->checks === [] ? 'no checks' : 'the checks '.implode(', ', $column->checks),
            $column->indexed ? 'an index' : 'no index',
        );
    }
}
