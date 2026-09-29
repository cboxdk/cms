<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use LogicException;
use Throwable;

/**
 * A row of the identity tables that the adapters cannot read. The schema's checks keep every row
 * readable, so this is a bug or a write from outside the kernel, never a caller's mistake.
 */
#[Internal]
final class UnreadableIdentityRow extends LogicException
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
        return new self(sprintf('The column %s.%s is %s, which the adapter does not read.', $table, $column, $type));
    }

    public static function refused(string $table, Throwable $previous): self
    {
        return new self(sprintf('A row of %s is not a valid identity value: %s', $table, $previous->getMessage()), 0, $previous);
    }
}
