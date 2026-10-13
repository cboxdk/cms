<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\TypeDefinition;

/**
 * The row of a language in the type table of a type with localization variants (PRD 10.1, 11.6):
 * the language's variant holds the translatable fields and the shared variant the others, and the
 * row holds both, so a query on one language never joins the shared variant. of() merges the two
 * variants' fields by the type's definition: each top-level field from the variant that holds it
 * (FieldDefinition::heldBy()), a field the type does not declare left out, and a namespace kept
 * only while it has a field.
 */
#[Internal]
final readonly class LocaleRow
{
    public static function of(TypeDefinition $type, FieldValues $shared, FieldValues $locale): FieldValues
    {
        $localization = $type->capabilities->localization;
        $own = [];
        /** @var array<string, list<NamedValue>> $extensions */
        $extensions = [];

        foreach ($type->fields as $field) {
            $source = $field->heldBy(VariantKey::shared(), $localization) ? $shared : $locale;
            $map = $field->namespace instanceof FieldNamespace ? $source->extension($field->namespace) : $source->own;
            $value = $map?->get($field->handle);

            if ($value === null) {
                continue;
            }

            if ($field->namespace instanceof FieldNamespace) {
                $extensions[$field->namespace->value][] = new NamedValue($field->handle, $value);
            } else {
                $own[] = new NamedValue($field->handle, $value);
            }
        }

        $merged = [];

        foreach ($extensions as $namespace => $fields) {
            $merged[] = new ExtensionFields(new FieldNamespace($namespace), new FieldMap(...$fields));
        }

        return new FieldValues(new FieldMap(...$own), ...$merged);
    }
}
