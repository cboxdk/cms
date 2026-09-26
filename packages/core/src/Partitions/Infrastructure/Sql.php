<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Quoting for the DDL the partition manager writes. Names and bounds come from the validated
 * policy, and are quoted anyway.
 */
#[Internal]
final readonly class Sql
{
    public static function identifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    public static function qualified(string $schema, string $name): string
    {
        return self::identifier($schema).'.'.self::identifier($name);
    }

    public static function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
