<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Schema;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use InvalidArgumentException;

/**
 * A type name, a type definition, a field definition or a column that breaks its invariants.
 */
#[Experimental]
final class InvalidTypeDefinition extends InvalidArgumentException
{
    public static function typeName(string $value): self
    {
        return new self(sprintf(
            'A type name is <owner>:<handle>: an owner of a lowercase letter and at most 19 lowercase letters and digits, not "ext", and a handle in lowercase snake_case of at most 63 characters, not "ext" and not starting with "cms_", such as "app:blog_post", got "%s".',
            self::shown($value),
        ));
    }

    public static function version(string $what, int $version): self
    {
        return new self(sprintf('The version of %s starts at 1, got %d.', $what, $version));
    }

    public static function column(string $name): self
    {
        return new self(sprintf(
            'A column name is a lowercase letter followed by lowercase letters, digits and underscores, at most 63 bytes, got "%s".',
            self::shown($name),
        ));
    }

    public static function columnType(string $column): self
    {
        return new self(sprintf('The column "%s" has no type.', self::shown($column)));
    }

    public static function fieldType(string $value): self
    {
        return new self(sprintf(
            'A field type is a core field type such as "text", or "<namespace>:<handle>" of a module or addon, got "%s".',
            self::shown($value),
        ));
    }

    public static function topLevelColumn(string $address): self
    {
        return new self(sprintf('The top-level field "%s" has no column in the type table.', $address));
    }

    public static function nestedColumn(string $address, string $group): self
    {
        return new self(sprintf('The field "%s" of the group "%s" has a column, but a group is one column.', $address, $group));
    }

    public static function nestedField(string $address, string $group): self
    {
        return new self(sprintf(
            'The field "%s" of the group "%s" differs from its group in namespace, classification or encryption, which a nested field takes from its group.',
            $address,
            $group,
        ));
    }

    public static function duplicateHandle(string $address): self
    {
        return new self(sprintf('The field "%s" appears twice.', $address));
    }

    public static function duplicateColumn(string $column): self
    {
        return new self(sprintf('The column "%s" appears twice in one type.', $column));
    }

    public static function duplicateNamespace(string $namespace): self
    {
        return new self(sprintf('The extension namespace "%s" appears twice in one type.', $namespace));
    }

    public static function unknownNamespace(string $address): self
    {
        return new self(sprintf('The field "%s" is in a namespace that extends no version of the type.', $address));
    }

    public static function agents(string $address, ClassificationAccess $classification): self
    {
        return new self(sprintf(
            'The field "%s" is classified %s and is visible to agents, which a field of that class never is (PRD 2.31).',
            $address,
            $classification->value,
        ));
    }

    public static function duplicateType(string $what): self
    {
        return new self(sprintf('The type %s appears twice in one catalog.', $what));
    }

    private static function shown(string $value): string
    {
        $cut = strlen($value) > 64 ? substr($value, 0, 64).'...' : $value;

        return addcslashes($cut, "\0..\37\177..\377\"\\");
    }
}
