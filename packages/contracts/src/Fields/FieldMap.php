<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Fields by handle: the fields of one owner of a type, or of a group. A handle appears at most
 * once, and the fields are sorted by handle, so the order they were given in is not part of the
 * map. A field that is absent is not in the map; a field that is present without a value holds
 * NullValue.
 */
#[Experimental]
final readonly class FieldMap
{
    /** @var list<NamedValue> */
    public array $fields;

    public function __construct(NamedValue ...$fields)
    {
        $byHandle = [];

        foreach ($fields as $field) {
            if (isset($byHandle[$field->handle->value])) {
                throw InvalidFieldValue::duplicateHandle($field->handle);
            }

            $byHandle[$field->handle->value] = $field;
        }

        ksort($byHandle, SORT_STRING);

        $this->fields = array_values($byHandle);
    }

    /**
     * The field's value, or null when the field is absent.
     */
    public function get(FieldHandle $handle): ?FieldValue
    {
        foreach ($this->fields as $field) {
            if ($field->handle->equals($handle)) {
                return $field->value;
            }
        }

        return null;
    }

    /**
     * The handles of the fields that are present, sorted.
     *
     * @return list<FieldHandle>
     */
    public function handles(): array
    {
        return array_map(static fn (NamedValue $field): FieldHandle => $field->handle, $this->fields);
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    public function equals(self $other): bool
    {
        if (count($other->fields) !== count($this->fields)) {
            return false;
        }

        foreach ($this->fields as $index => $field) {
            $theirs = $other->fields[$index];

            if (! $theirs->handle->equals($field->handle) || ! $field->value->equals($theirs->value)) {
                return false;
            }
        }

        return true;
    }
}
