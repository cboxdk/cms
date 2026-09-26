<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The column a top-level field gets in its type's table (PRD 11.6, 11.12). A type's own field is
 * the column of its handle. A field that an extension adds is `ext__<namespace>__<handle>`, where
 * the namespace is the extender, so two owners' fields never share a column; handles have no
 * double underscore, so the parts cannot run together. Postgres truncates an identifier longer
 * than 63 bytes, so such a name is refused rather than shortened.
 */
#[Internal]
final readonly class ColumnName
{
    /** Postgres' limit for an identifier, NAMEDATALEN - 1. */
    public const int MAX_BYTES = 63;

    /** What separates the parts of an extension field's column name. */
    public const string SEPARATOR = '__';

    private function __construct(public string $value) {}

    public static function ofTypeField(Handle $field): self
    {
        return new self($field->value);
    }

    public static function ofExtensionField(Owner $namespace, Handle $field): self
    {
        return new self(Handle::RESERVED.self::SEPARATOR.$namespace->value.self::SEPARATOR.$field->value);
    }

    public function bytes(): int
    {
        return strlen($this->value);
    }

    public function fits(): bool
    {
        return $this->bytes() <= self::MAX_BYTES;
    }
}
