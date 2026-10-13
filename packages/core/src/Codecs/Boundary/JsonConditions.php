<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Codecs\Boundary;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Validation\FieldRules;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Contracts\Validation\TypeRules;
use Cbox\Cms\Contracts\Validation\WhenHidden;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Validation\Boundary\InputConditionValues;
use stdClass;

/**
 * The conditions of a record's conditional fields (gap G3), which a generated record codec checks
 * once every value of the document has passed its rules: the record's fields hold the owner's
 * fields by handle and each extender's under `ext` by its namespace, as the type's validator reads
 * its input, so the conditions are read from the type's generated validator.
 *
 * A shown field the validator requires (Presence::Required) must have a value, and a hidden field
 * of WhenHidden::Clear must have none; a hidden field is never required. The fields are walked in
 * the validator's order, each before the fields nested in it, the owner's before the extenders',
 * and the first field that breaks a condition is refused with json_invalid at its path. A top-level
 * field classified above the caller's access is withheld, so whether it is required is not asked;
 * every other value the walk reaches is one the caller may read.
 */
#[Experimental]
final readonly class JsonConditions
{
    /**
     * @param  stdClass  $document  the record, read already by the codec
     * @param  array<string, ClassificationAccess>  $classified  the classification of each top-level field above public, by its handle or `ext.<namespace>.<handle>`
     *
     * @throws DecodingFailed with json_invalid
     */
    public static function check(stdClass $document, TypeRules $rules, ClassificationAccess $access, array $classified = []): void
    {
        $withheld = array_keys(array_filter($classified, static fn (ClassificationAccess $classification): bool => ! $access->allows($classification)));
        self::fields($rules->fields, get_object_vars($document), null, [], $withheld, '');
        $extensions = $document->{TypeRules::EXTENSIONS_KEY} ?? null;

        foreach ($rules->extensions as $extension) {
            $namespace = $extension->namespace->value;
            $fields = $extensions instanceof stdClass ? ($extensions->{$namespace} ?? null) : null;

            if ($fields instanceof stdClass) {
                self::fields($extension->fields, get_object_vars($fields), new FieldPath(TypeRules::EXTENSIONS_KEY)->then($namespace), [], $withheld, TypeRules::EXTENSIONS_KEY.'.'.$namespace.'.');
            }
        }
    }

    /**
     * @param  list<FieldRules>  $rules
     * @param  array<array-key, mixed>  $object
     * @param  list<array<array-key, mixed>>  $ancestors
     * @param  list<string>  $withheld  the top-level fields the caller may not read
     * @param  ?string  $prefix  what a top-level field's address has before its handle, or null inside a group
     *
     * @throws DecodingFailed with json_invalid
     */
    private static function fields(array $rules, array $object, ?FieldPath $at, array $ancestors, array $withheld, ?string $prefix): void
    {
        $scopes = [...$ancestors, $object];

        foreach ($rules as $field) {
            $handle = $field->handle->value;
            $value = $object[$handle] ?? null;
            $path = JsonValues::at($at, $handle);
            $shown = $field->shownFor(new InputConditionValues($scopes));

            if ($value === null) {
                if ($shown && $field->presence === Presence::Required && ($prefix === null || ! in_array($prefix.$handle, $withheld, true))) {
                    throw DecodingFailed::invalid($path, 'is missing, and the field is shown and required');
                }

                continue;
            }

            if (! $shown && $field->whenHidden === WhenHidden::Clear) {
                throw DecodingFailed::invalid($path, 'has a value, and the field is hidden because its condition does not hold');
            }

            foreach (self::objects($value, $path) as [$nestedAt, $nested]) {
                self::fields($field->fields, $nested, $nestedAt, $scopes, $withheld, null);
            }
        }
    }

    /**
     * The objects a group's value holds, each with its path: the object of a group, or each item
     * of a repeated group; none for any other value.
     *
     * @return list<array{FieldPath, array<array-key, mixed>}>
     */
    private static function objects(mixed $value, FieldPath $path): array
    {
        if ($value instanceof stdClass) {
            return [[$path, get_object_vars($value)]];
        }

        if (! is_array($value)) {
            return [];
        }

        $objects = [];

        foreach (array_values($value) as $index => $item) {
            if ($item instanceof stdClass) {
                $objects[] = [$path->then($index), get_object_vars($item)];
            }
        }

        return $objects;
    }
}
