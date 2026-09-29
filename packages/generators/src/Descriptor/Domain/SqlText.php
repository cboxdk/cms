<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Descriptor\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * How the type descriptor writes a name or a value into the SQL of a CHECK constraint. A column is
 * always a quoted identifier, because a handle such as `order` or `user` is a word Postgres
 * reserves, and a text is a quoted literal with each quote doubled.
 */
#[Internal]
final readonly class SqlText
{
    public static function identifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    public static function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * The literals of the values, separated by a comma and a space: `'a', 'b'`.
     *
     * @param  list<string>  $values
     */
    public static function literals(array $values): string
    {
        return implode(', ', array_map(self::literal(...), $values));
    }
}
