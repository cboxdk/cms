<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Override;

/**
 * Values by string key, for structured content whose keys are not field handles, such as the
 * blocks and spans of a rich text document (PRD 11.10). A key appears at most once, and the
 * entries are sorted by key, so the order they were given in is not part of the value.
 */
#[Experimental]
final readonly class MapValue implements FieldValue
{
    /** @var list<MapEntry> */
    public array $entries;

    public function __construct(MapEntry ...$entries)
    {
        $byKey = [];

        foreach ($entries as $entry) {
            if (isset($byKey[$entry->key])) {
                throw InvalidFieldValue::duplicateMapKey($entry->key);
            }

            $byKey[$entry->key] = $entry;
        }

        ksort($byKey, SORT_STRING);

        $this->entries = array_values($byKey);
    }

    public function get(string $key): ?FieldValue
    {
        foreach ($this->entries as $entry) {
            if ($entry->key === $key) {
                return $entry->value;
            }
        }

        return null;
    }

    #[Override]
    public function equals(FieldValue $other): bool
    {
        if (! $other instanceof self || count($other->entries) !== count($this->entries)) {
            return false;
        }

        foreach ($this->entries as $index => $entry) {
            $theirs = $other->entries[$index];

            if ($theirs->key !== $entry->key || ! $entry->value->equals($theirs->value)) {
                return false;
            }
        }

        return true;
    }
}
