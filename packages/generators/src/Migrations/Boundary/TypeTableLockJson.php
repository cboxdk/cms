<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Migrations\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Contracts\Schema\InvalidTypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Migrations\Domain\Dto\LockedColumn;
use Cbox\Cms\Generators\Migrations\Domain\Dto\TypeTableLock;
use Cbox\Cms\Generators\Schema\Domain\Localization;
use Cbox\Cms\Generators\Schema\Domain\Stages;
use Cbox\Cms\Generators\Schema\Domain\TypeId;
use JsonException;
use Throwable;

/**
 * The JSON form of a schema lock file (PRD 11.6), written and read here alone, so one Boundary
 * owns the format TypeTableLock::FORMAT (GUARDRAILS 2.2).
 *
 * encode() writes every key, the keys of each object in sorted order, the columns in the lock's
 * order (by step, then by name), pretty-printed with four spaces, unescaped slashes and unescaped
 * Unicode, ending with one newline, so the same lock always gives the same bytes. The migrations
 * generator gets it as a closure, because the domain writes no JSON.
 *
 * decode() takes only a lock in that form: format 1, every key, the table `<owner>__<handle>` of
 * its type and its file name, the columns sorted by step and then by name with each name once,
 * each step from 1 to `steps`, and every step after the first with a column; and the text must be
 * exactly what encode() writes for it. Anything else is generate_lock_invalid, so a lock edited by
 * hand is never silently taken or rewritten.
 */
#[Internal]
final readonly class TypeTableLockJson
{
    public static function encode(TypeTableLock $lock): string
    {
        $columns = [];

        foreach ($lock->columns as $column) {
            $columns[] = [
                'checks' => $column->checks,
                'indexed' => $column->indexed,
                'name' => $column->name,
                'not_null' => $column->notNull,
                'step' => $column->step,
                'type' => $column->type,
            ];
        }

        $document = [
            'about' => sprintf(
                'The schema lock of the type table %s: the columns its migrations %s_<step> build, in %d %s. cms:generate writes the lock and the migrations from it; do not edit either.',
                $lock->table,
                $lock->table,
                $lock->steps,
                $lock->steps === 1 ? 'step' : 'steps',
            ),
            'columns' => $columns,
            'localization' => $lock->localization->value,
            'lock' => TypeTableLock::FORMAT,
            'stages' => $lock->stages->value,
            'steps' => $lock->steps,
            'table' => $lock->table,
            'type' => $lock->type->value,
            'type_id' => $lock->typeId->toString(),
        ];

        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @param  string  $file  the lock's path relative to the root, for the message
     *
     * @throws GenerationFailed with GenerateErrorCode::LockInvalid
     */
    public static function decode(string $file, string $json): TypeTableLock
    {
        try {
            $document = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw self::invalid($file, 'it is not valid JSON: '.$invalid->getMessage(), $invalid);
        }

        if (! is_array($document) || ($document['lock'] ?? null) !== TypeTableLock::FORMAT) {
            throw self::invalid($file, sprintf('it is not a schema lock of format %d.', TypeTableLock::FORMAT));
        }

        try {
            $type = new TypeName(self::string($file, $document, 'type'));
            $typeId = TypeId::fromString(self::string($file, $document, 'type_id'));
        } catch (InvalidTypeDefinition|InvalidUuid7 $invalid) {
            throw self::invalid($file, $invalid->getMessage(), $invalid);
        }

        $table = self::string($file, $document, 'table');
        $stages = Stages::tryFrom(self::string($file, $document, 'stages')) ?? throw self::invalid($file, 'its stages are not a value of the blueprint schema v1.');
        $localization = Localization::tryFrom(self::string($file, $document, 'localization')) ?? throw self::invalid($file, 'its localization is not a value of the blueprint schema v1.');
        $steps = self::integer($file, $document, 'steps');

        if ($steps < 1) {
            throw self::invalid($file, 'it has no step.');
        }

        if ($table !== $type->table() || basename($file) !== $table.'.'.TypeTableLock::EXTENSION) {
            throw self::invalid($file, sprintf('its table %s is not %s, the table of its type %s, or its file is not named after it.', $table, $type->table(), $type->value));
        }

        $columns = self::columns($file, $document['columns'] ?? null, $steps);
        $lock = new TypeTableLock($table, $type, $typeId, $stages, $localization, $steps, $columns);

        if (self::encode($lock) !== $json) {
            throw self::invalid($file, 'it is not in the form cms:generate writes. It was edited by hand.');
        }

        return $lock;
    }

    /**
     * @return list<LockedColumn>
     *
     * @throws GenerationFailed with GenerateErrorCode::LockInvalid
     */
    private static function columns(string $file, mixed $value, int $steps): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw self::invalid($file, 'its columns are not a list.');
        }

        $columns = [];
        $names = [];
        $previous = null;
        $stepsWithColumns = [];

        foreach ($value as $index => $entry) {
            if (! is_array($entry)) {
                throw self::invalid($file, sprintf('column %d is not an object.', $index));
            }

            $at = sprintf('column %d', $index);
            $checks = $entry['checks'] ?? null;

            if (! is_array($checks) || ! array_is_list($checks) || array_filter($checks, is_string(...)) !== $checks) {
                throw self::invalid($file, $at.' has no list of checks.');
            }

            $column = new LockedColumn(
                self::string($file, $entry, 'name', $at),
                self::string($file, $entry, 'type', $at),
                self::boolean($file, $entry, 'not_null', $at),
                $checks,
                self::boolean($file, $entry, 'indexed', $at),
                self::integer($file, $entry, 'step', $at),
            );

            if ($column->step < 1 || $column->step > $steps) {
                throw self::invalid($file, sprintf('%s has step %d, and the lock has steps 1 to %d.', $at, $column->step, $steps));
            }

            if (isset($names[$column->name])) {
                throw self::invalid($file, sprintf('the column %s is in it twice.', $column->name));
            }

            if ($previous instanceof LockedColumn && [$previous->step, $previous->name] > [$column->step, $column->name]) {
                throw self::invalid($file, 'its columns are not sorted by step and then by name.');
            }

            $names[$column->name] = true;
            $stepsWithColumns[$column->step] = true;
            $previous = $column;
            $columns[] = $column;
        }

        for ($step = 2; $step <= $steps; $step++) {
            if (! isset($stepsWithColumns[$step])) {
                throw self::invalid($file, sprintf('its step %d adds no column.', $step));
            }
        }

        return $columns;
    }

    /**
     * @param  array<mixed>  $object
     *
     * @throws GenerationFailed with GenerateErrorCode::LockInvalid
     */
    private static function string(string $file, array $object, string $key, string $at = 'it'): string
    {
        $value = $object[$key] ?? null;

        return is_string($value) ? $value : throw self::invalid($file, sprintf('%s has no string %s.', $at, $key));
    }

    /**
     * @param  array<mixed>  $object
     *
     * @throws GenerationFailed with GenerateErrorCode::LockInvalid
     */
    private static function integer(string $file, array $object, string $key, string $at = 'it'): int
    {
        $value = $object[$key] ?? null;

        return is_int($value) ? $value : throw self::invalid($file, sprintf('%s has no integer %s.', $at, $key));
    }

    /**
     * @param  array<mixed>  $object
     *
     * @throws GenerationFailed with GenerateErrorCode::LockInvalid
     */
    private static function boolean(string $file, array $object, string $key, string $at): bool
    {
        $value = $object[$key] ?? null;

        return is_bool($value) ? $value : throw self::invalid($file, sprintf('%s has no boolean %s.', $at, $key));
    }

    private static function invalid(string $file, string $reason, ?Throwable $previous = null): GenerationFailed
    {
        return GenerationFailed::because(GenerateErrorCode::LockInvalid, sprintf(
            'The schema lock %s cannot be used: %s Restore the committed file with git, then run cms:generate again.',
            $file,
            $reason,
        ), $previous);
    }
}
