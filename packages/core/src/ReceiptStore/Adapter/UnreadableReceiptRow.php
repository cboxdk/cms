<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\ReceiptStore\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use LogicException;
use Throwable;

/**
 * A row of the receipt tables that the store cannot read. The store writes only rows it can read
 * back, so this is a bug or a write from outside the store, never a caller's mistake.
 */
#[Internal]
final class UnreadableReceiptRow extends LogicException
{
    public static function notARow(string $table, string $type): self
    {
        return new self(sprintf('Expected a row of %s as an object, got %s.', $table, $type));
    }

    public static function missingColumn(string $table, string $column): self
    {
        return new self(sprintf('The row of %s has no column %s.', $table, $column));
    }

    public static function wrongType(string $table, string $column, string $type): self
    {
        return new self(sprintf('The column %s.%s is %s, expected text.', $table, $column, $type));
    }

    public static function unknownValue(string $column, string $value): self
    {
        return new self(sprintf('The column %s holds "%s", which the store never writes.', $column, $value));
    }

    public static function refused(string $table, Throwable $previous): self
    {
        return new self(sprintf('A row of %s does not make a valid receipt: %s', $table, $previous->getMessage()), 0, $previous);
    }
}
