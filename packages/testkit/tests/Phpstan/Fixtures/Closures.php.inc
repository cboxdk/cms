<?php

declare(strict_types=1);

namespace Fixture\Entries\Domain;

final class ClosureEntry {}

function untyped(array $entries): mixed
{
    return $entries;
}

/**
 * @param list<ClosureEntry> $entries
 * @return list<ClosureEntry>
 */
function typed(array $entries): array
{
    return $entries;
}

final class Closures
{
    /**
     * @param list<ClosureEntry> $entries
     * @return list<int>
     */
    public function run(array $entries): array
    {
        $count = static fn (array $items): int => count($items);
        $wrap = static fn (ClosureEntry $entry): array => [$entry];
        $keep = static function (mixed $value): bool {
            return $value !== null;
        };
        $inferred = static fn ($entry) => [$entry];

        return array_map(static fn (ClosureEntry $entry): int => 1, array_filter($entries, $keep));
    }
}

namespace Fixture\Http\Boundary;

function untyped(array $entries): mixed
{
    return static fn (array $items): array => $items;
}
