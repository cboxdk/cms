<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Editors;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use InvalidArgumentException;

/**
 * A type editor descriptor, or a field of one, that breaks its invariants.
 */
#[Experimental]
final class InvalidTypeEditor extends InvalidArgumentException
{
    public static function version(string $type, int $version): self
    {
        return new self(sprintf('The version of %s starts at 1, got %d.', $type, $version));
    }

    public static function label(string $what): self
    {
        return new self(sprintf('The label of %s is 1 to %d characters.', $what, EditorField::MAX_LABEL));
    }

    public static function description(string $what): self
    {
        return new self(sprintf('The description of %s is null or 1 to %d characters.', $what, EditorField::MAX_DESCRIPTION));
    }

    public static function fieldType(string $value): self
    {
        return new self(sprintf('A field type is a core field type such as "text", or <namespace>:<handle> for an addon\'s, got "%s".', self::shown($value)));
    }

    public static function path(string $path, string $expected): self
    {
        return new self(sprintf('The path of a field is its parent\'s path and its handle, or ext.<namespace>.<handle> for a top-level extension field, so "%s" should be "%s".', self::shown($path), $expected));
    }

    public static function agents(string $path, ClassificationAccess $classification): self
    {
        return new self(sprintf('The field %s is %s, which agents never see, so it cannot be open to agents.', $path, $classification->value));
    }

    public static function choices(string $path): self
    {
        return new self(sprintf('The field %s has choices only when its base is select, and a select has at least one.', $path));
    }

    public static function repeat(string $path): self
    {
        return new self(sprintf('The field %s repeats only when its base is group.', $path));
    }

    public static function rule(string $path): self
    {
        return new self(sprintf('The field %s has a rule unless its base is group, whose rule the form builds from its nested fields.', $path));
    }

    public static function items(int $min, int $max): self
    {
        return new self(sprintf('A repeat has min_items of at least 0 and max_items of at least 1 and at least min_items, got %d and %d.', $min, $max));
    }

    public static function duplicateChoice(string $path, string $value): self
    {
        return new self(sprintf('The field %s has the choice "%s" twice.', $path, $value));
    }

    public static function duplicateField(string $type, string $path): self
    {
        return new self(sprintf('The type %s has the field %s twice.', $type, $path));
    }

    public static function parent(string $type, string $path, string $parent): self
    {
        return new self(sprintf('The field %s of %s is below %s, which is not a group listed before it.', $path, $type, $parent));
    }

    public static function nested(string $type, string $path): self
    {
        return new self(sprintf('The field %s of %s takes the namespace, classification and encryption of its group.', $path, $type));
    }

    public static function extension(string $type, string $path): self
    {
        return new self(sprintf('The field %s of %s belongs to a namespace the type is not extended by.', $path, $type));
    }

    public static function duplicateType(string $what): self
    {
        return new self(sprintf('The descriptors hold %s twice; each type has one descriptor, and each id and each name is one type.', $what));
    }

    public static function duplicateExtension(string $type, string $namespace): self
    {
        return new self(sprintf('The type %s is extended by %s twice.', $type, $namespace));
    }

    public static function titleField(string $type, string $path): self
    {
        return new self(sprintf('The title field %s of %s is not a top-level text field of the owner.', $path, $type));
    }

    private static function shown(string $value): string
    {
        return strlen($value) > 80 ? substr($value, 0, 77).'...' : $value;
    }
}
