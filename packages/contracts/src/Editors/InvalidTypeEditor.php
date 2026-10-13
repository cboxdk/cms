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

    public static function fallback(string $path): self
    {
        return new self(sprintf('The field %s has a field fallback and is not translatable; only a translatable field falls back on its own (PRD 10.3).', $path));
    }

    public static function translatable(string $type, string $path): self
    {
        return new self(sprintf('The field %s is translatable, and the type %s has localization none, whose one shared variant holds every field (PRD 10.1).', $path, $type));
    }

    public static function titleField(string $type, string $path): self
    {
        return new self(sprintf('The title field %s of %s is not a top-level text field of the owner.', $path, $type));
    }

    public static function text(string $locale): self
    {
        return new self(sprintf('A text for the locale %s is 1 to %d characters.', $locale, EditorText::MAX_TEXT));
    }

    public static function duplicateLocale(string $what, string $locale): self
    {
        return new self(sprintf('%s has the locale %s twice.', ucfirst($what), $locale));
    }

    public static function instructions(string $what): self
    {
        return new self(sprintf('The instructions of %s are null or 1 to %d characters.', $what, EditorText::MAX_TEXT));
    }

    public static function inputHint(): self
    {
        return new self(sprintf('The input hint of a field is null or 1 to %d characters.', EditorField::MAX_LABEL));
    }

    public static function summary(?string $path = null): self
    {
        return $path === null
            ? new self(sprintf('The summary of a field is null or 1 to %d characters.', EditorUi::MAX_SUMMARY))
            : new self(sprintf('The field %s has a summary, which only a repeated group has.', $path));
    }

    public static function display(string $path): self
    {
        return new self(sprintf('The field %s has a display, which only a select has.', $path));
    }

    public static function defaultValue(string $path): self
    {
        return new self(sprintf('The field %s has a default, which a group or rich text never has.', $path));
    }

    public static function when(string $path): self
    {
        return new self(sprintf('The field %s has a condition without when_hidden, or when_hidden without a condition; a conditional field has both.', $path));
    }

    public static function placement(): self
    {
        return new self('A placement names the path of a field.');
    }

    public static function placedTwice(string $path): self
    {
        return new self(sprintf('The layout places the field %s twice.', $path));
    }

    public static function duplicateTab(string $handle): self
    {
        return new self(sprintf('The layout has the tab %s twice.', $handle));
    }

    public static function duplicateSection(string $where, string $handle): self
    {
        return new self(sprintf('%s has the section %s twice.', ucfirst($where), $handle));
    }

    public static function layoutPlacesTwice(string $type, string $path): self
    {
        return new self(sprintf('The layout of %s places the field %s twice; every top-level field is placed exactly once.', $type, $path));
    }

    public static function layoutPlacesUnknown(string $type, string $path): self
    {
        return new self(sprintf('The layout of %s places %s, which is not a top-level field of the type; a layout places only those, each exactly once.', $type, $path));
    }

    public static function layoutLeavesOut(string $type, string $path): self
    {
        return new self(sprintf('The layout of %s leaves out the field %s; every top-level field is placed exactly once.', $type, $path));
    }

    private static function shown(string $value): string
    {
        return strlen($value) > 80 ? substr($value, 0, 77).'...' : $value;
    }
}
