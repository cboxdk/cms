<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\ReceiptStore\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\InvalidReceipt;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Maps the rows of `receipts` and `receipt_projections` to a StoredReceipt (GUARDRAILS 2.2).
 *
 * The columns are read with the types the queries promise. A value the domain refuses, such as
 * an unknown retention class or a malformed id, means the rows were written by something other than the
 * store, and throws UnreadableReceiptRow naming the column.
 */
#[Internal]
final readonly class ReceiptRows
{
    /**
     * @param  object  $receipt  a row of `receipts`: changeset_id, retention_class
     * @param  array<array-key, mixed>  $projections  rows of `receipt_projections`: projection, state, acknowledged_at
     */
    public static function receipt(object $receipt, array $projections): StoredReceipt
    {
        $statuses = [];

        foreach ($projections as $row) {
            if (! is_object($row)) {
                throw UnreadableReceiptRow::notARow(PostgresReceiptStore::PROJECTIONS, get_debug_type($row));
            }

            $statuses[] = self::projection($row);
        }

        try {
            return new StoredReceipt(
                self::changesetId($receipt),
                self::enum(RetentionClass::class, self::retentionClassOf($receipt), 'retention_class'),
                $statuses,
            );
        } catch (InvalidReceipt $invalid) {
            throw UnreadableReceiptRow::refused(PostgresReceiptStore::RECEIPTS, $invalid);
        }
    }

    /**
     * The retention class column of a `receipts` row, as stored.
     */
    public static function retentionClassOf(object $receipt): string
    {
        return self::string($receipt, 'retention_class', PostgresReceiptStore::RECEIPTS);
    }

    private static function projection(object $row): ProjectionStatus
    {
        $table = PostgresReceiptStore::PROJECTIONS;
        $at = self::nullableString($row, 'acknowledged_at', $table);

        try {
            return new ProjectionStatus(
                new ProjectionName(self::string($row, 'projection', $table)),
                self::enum(ProjectionState::class, self::string($row, 'state', $table), 'state'),
                $at === null ? null : self::time($at),
            );
        } catch (InvalidReceipt $invalid) {
            throw UnreadableReceiptRow::refused($table, $invalid);
        }
    }

    private static function changesetId(object $receipt): ChangesetId
    {
        $value = self::string($receipt, 'changeset_id', PostgresReceiptStore::RECEIPTS);

        try {
            return ChangesetId::fromString($value);
        } catch (InvalidUuid7 $invalid) {
            throw UnreadableReceiptRow::refused(PostgresReceiptStore::RECEIPTS, $invalid);
        }
    }

    /**
     * @template T of RetentionClass|ProjectionState
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private static function enum(string $enum, string $value, string $column): RetentionClass|ProjectionState
    {
        return $enum::tryFrom($value) ?? throw UnreadableReceiptRow::unknownValue($column, $value);
    }

    private static function time(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value)->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            throw UnreadableReceiptRow::unknownValue('acknowledged_at', $value);
        }
    }

    private static function string(object $row, string $column, string $table): string
    {
        $value = self::nullableString($row, $column, $table);

        return $value ?? throw UnreadableReceiptRow::wrongType($table, $column, 'null');
    }

    private static function nullableString(object $row, string $column, string $table): ?string
    {
        if (! property_exists($row, $column)) {
            throw UnreadableReceiptRow::missingColumn($table, $column);
        }

        $value = $row->{$column};

        if ($value !== null && ! is_string($value)) {
            throw UnreadableReceiptRow::wrongType($table, $column, get_debug_type($value));
        }

        return $value;
    }
}
