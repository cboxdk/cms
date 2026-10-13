<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\FieldFallback;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Routing\Domain\Dto\FallbackResolution;
use Cbox\Cms\Core\Routing\Domain\Dto\FallbackStep;
use Cbox\Cms\Core\Routing\Domain\Dto\ReleasedRow;

/**
 * The fallback of PRD 10.3 for an entry of a type with localization variants, over the released
 * rows of the locales a read tries (FallbackChain::from()):
 *
 * - Per variant, the default: the page shows the first locale tried that has a released row, which
 *   holds the shared fields too (PRD 11.6), so a language whose own variant has no release shows
 *   the next language of the chain whole, never a mix. No released row in any of them, and the
 *   page shows nothing.
 * - Per field, for a translatable field its blueprint marks `fallback: field`: when the shown
 *   variant leaves it empty, it takes the value of the first later locale tried whose released row
 *   has one. Empty is absent, null, an empty text or an empty list, such as rich text without a
 *   block. A group is one field, and falls back whole.
 */
#[Internal]
final readonly class LocaleFallback
{
    /**
     * @param  non-empty-list<Locale>  $tried  the locale requested first
     * @param  list<ReleasedRow>  $rows  the released rows of the entry in the locales tried, in any order
     */
    public static function resolve(TypeDefinition $type, array $tried, array $rows): FallbackResolution
    {
        $locales = [];
        $found = [];

        foreach ($tried as $locale) {
            $row = array_find($rows, static fn (ReleasedRow $row): bool => $row->variant->equals(VariantKey::of($locale)));

            if ($row instanceof ReleasedRow) {
                $locales[] = $locale;
                $found[] = $row->fields;
            }
        }

        $resolved = $locales[0] ?? null;
        $fields = $found[0] ?? null;

        if (! $resolved instanceof Locale || ! $fields instanceof FieldValues) {
            return new FallbackResolution(new FallbackStep($tried, null, false, []), null);
        }

        $later = array_slice($found, 1);
        $own = $fields->own;
        $extensions = [];
        $fellBack = [];

        foreach ($type->fields as $definition) {
            if (! $definition->translatable || $definition->fallback !== FieldFallback::Field) {
                continue;
            }

            $value = self::map($fields, $definition->namespace)?->get($definition->handle);

            if (! self::isEmpty($value)) {
                continue;
            }

            $taken = self::firstFilled($definition, $later);

            if (! $taken instanceof FieldValue) {
                continue;
            }

            $fellBack[$definition->address()] = FieldPath::fromString($definition->address());

            if ($definition->namespace instanceof FieldNamespace) {
                $namespace = $definition->namespace->value;
                $extensions[$namespace] = self::with($extensions[$namespace] ?? self::map($fields, $definition->namespace) ?? new FieldMap, $definition, $taken);
            } else {
                $own = self::with($own, $definition, $taken);
            }
        }

        ksort($fellBack, SORT_STRING);

        return new FallbackResolution(
            new FallbackStep($tried, $resolved, ! $resolved->equals($tried[0]), array_values($fellBack)),
            new FieldValues($own, ...self::extensions($fields, $extensions)),
        );
    }

    /**
     * Whether a value counts as left empty: absent, null, an empty text or an empty list.
     */
    public static function isEmpty(?FieldValue $value): bool
    {
        return match (true) {
            ! $value instanceof FieldValue, $value instanceof NullValue => true,
            $value instanceof TextValue => $value->value === '',
            $value instanceof ListValue => $value->items === [],
            default => false,
        };
    }

    /**
     * The value of the field in the first of the rows that does not leave it empty, or null.
     *
     * @param  list<FieldValues>  $rows
     */
    private static function firstFilled(FieldDefinition $definition, array $rows): ?FieldValue
    {
        foreach ($rows as $row) {
            $value = self::map($row, $definition->namespace)?->get($definition->handle);

            if (! self::isEmpty($value)) {
                return $value;
            }
        }

        return null;
    }

    private static function map(FieldValues $fields, ?FieldNamespace $namespace): ?FieldMap
    {
        return $namespace instanceof FieldNamespace ? $fields->extension($namespace) : $fields->own;
    }

    private static function with(FieldMap $map, FieldDefinition $definition, FieldValue $value): FieldMap
    {
        $kept = array_filter($map->fields, static fn (NamedValue $field): bool => ! $field->handle->equals($definition->handle));

        return new FieldMap(...[...$kept, new NamedValue($definition->handle, $value)]);
    }

    /**
     * The row's extensions, with the maps whose fields fell back in place of their own.
     *
     * @param  array<string, FieldMap>  $replaced  by namespace
     * @return list<ExtensionFields>
     */
    private static function extensions(FieldValues $fields, array $replaced): array
    {
        $extensions = [];

        foreach ($fields->extensions as $extension) {
            $extensions[$extension->namespace->value] = new ExtensionFields($extension->namespace, $replaced[$extension->namespace->value] ?? $extension->fields);
        }

        foreach ($replaced as $namespace => $map) {
            $extensions[$namespace] ??= new ExtensionFields(new FieldNamespace($namespace), $map);
        }

        return array_values($extensions);
    }
}
