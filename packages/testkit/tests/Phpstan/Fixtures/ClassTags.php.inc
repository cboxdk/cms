<?php

declare(strict_types=1);

namespace Fixture\Entries\Domain;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

final class TagEntry {}

/**
 * @template TValue
 */
abstract class Collection {}

/**
 * @extends Collection<mixed>
 * @implements IteratorAggregate<int, TagEntry>
 *
 * @property array<string, mixed> $attributes
 * @property-read list<TagEntry> $entries
 * @method array rows(mixed $filter)
 * @method list<TagEntry> all()
 */
final class Entries extends Collection implements IteratorAggregate
{
    /** @return Traversable<int, TagEntry> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator([]);
    }
}

/**
 * @template TKey of array-key
 * @template TRow of array
 */
final class Rows {}

/**
 * @extends Collection<TagEntry>
 */
final class TypedEntries extends Collection {}

namespace Fixture\Receipts\Adapter;

use Fixture\Entries\Domain\Collection;

/**
 * @extends Collection<mixed>
 * @property array<string, mixed> $attributes
 */
final class Rows extends Collection {}
