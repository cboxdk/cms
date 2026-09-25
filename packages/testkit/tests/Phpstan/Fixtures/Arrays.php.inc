<?php

declare(strict_types=1);

namespace Fixture\Entries\Domain;

use Generator;
use Traversable;

final class Entry {}

/**
 * @template T
 */
final class Box {}

interface Arrays
{
    public function untyped(): array;

    /** @return list<Entry> */
    public function list(): array;

    /** @return array<string, Entry> */
    public function map(): array;

    /** @return array<string, mixed> */
    public function mixedMap(): array;

    /** @return Entry[] */
    public function bracketed(): array;

    /** @return array<Entry> */
    public function valueOnly(): array;

    /** @return list<mixed> */
    public function mixedList(): array;

    /** @return array{id: int} */
    public function shape(): array;

    /** @return list<array<string, Entry>> */
    public function nested(): array;

    /** @return list<array> */
    public function nestedUntyped(): array;

    /** @return non-empty-list<Entry> */
    public function nonEmpty(): array;

    /** @return Box<mixed> */
    public function mixedGeneric(): Box;

    /** @return Box<list<Entry>> */
    public function typedGeneric(): Box;

    /** @return Traversable<int, Entry> */
    public function traversable(): Traversable;

    /** @return Generator<int, Entry, null, void> */
    public function generator(): Generator;

    /** @return Generator<int, Entry> */
    public function shortGenerator(): Generator;

    /** @param callable(mixed): void $callback */
    public function callback(callable $callback): void;

    /** @param array<string, Entry>|null $entries */
    public function nullable(?array $entries): void;

    /**
     * @template TEntry
     * @param TEntry $entry
     * @return TEntry
     */
    public function generic(mixed $entry): mixed;

    /**
     * @template TArray of array
     * @param TArray $entries
     */
    public function looseBound(array $entries): void;
}
