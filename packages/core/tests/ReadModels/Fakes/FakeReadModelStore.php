<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\ReadModels\Fakes;

use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\ReadModels\Domain\Dto\ChunkResult;
use Cbox\Cms\Core\ReadModels\Domain\EntryRange;
use Cbox\Cms\Core\ReadModels\Domain\ReadModelStore;
use Cbox\Cms\Core\ReadModels\Domain\RebuildRefused;
use Closure;
use InvalidArgumentException;
use Override;

/**
 * ReadModelStore without a database: the entries a test added, each of one type with its shared
 * variant's payload at a schema version, the type's current one unless the test gives another. It
 * plans and rebuilds as the Postgres store does, and records the plans asked for and the ranges it
 * rebuilt, with the context of each. A test can make the next rebuild of a range run something
 * first with before(), such as a failure.
 */
final class FakeReadModelStore implements ReadModelStore
{
    /** @var list<array{TypeDefinition, int, AccessContext}> the type, chunk size and context of each plan */
    public array $planned = [];

    /** @var list<array{EntryRange, AccessContext}> the ranges rebuilt, in order */
    public array $rebuilt = [];

    /** @var array<string, array<string, int>> by type id, each entry's schema version by entry id */
    private array $entries = [];

    /** @var array<string, Closure(): void> by chunk name, run once before that range is rebuilt */
    private array $before = [];

    public function add(TypeDefinition $type, EntryId $entry, ?int $schemaVersion = null): self
    {
        $this->entries[$type->id->toString()][$entry->toString()] = $schemaVersion ?? $type->version;

        return $this;
    }

    /**
     * @param  Closure(): void  $work
     */
    public function before(EntryRange $range, Closure $work): self
    {
        $this->before[$range->chunk()->value] = $work;

        return $this;
    }

    #[Override]
    public function plan(TypeDefinition $type, AccessContext $access, int $chunkSize): array
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException(sprintf('A range of a rebuild holds at least one entry, got a chunk size of %d.', $chunkSize));
        }

        $this->planned[] = [$type, $chunkSize, $access];

        return array_map(
            static fn (array $ids): EntryRange => new EntryRange(EntryId::fromString($ids[0]), EntryId::fromString($ids[count($ids) - 1])),
            array_chunk($this->sorted($type), $chunkSize),
        );
    }

    #[Override]
    public function rebuild(TypeDefinition $type, AccessContext $access, EntryRange $range): ChunkResult
    {
        $chunk = $range->chunk();

        if (isset($this->before[$chunk->value])) {
            $work = $this->before[$chunk->value];
            unset($this->before[$chunk->value]);
            $work();
        }

        $inRange = array_values(array_filter(
            $this->sorted($type),
            static fn (string $id): bool => strcmp($id, $range->first->toString()) >= 0 && strcmp($id, $range->last->toString()) <= 0,
        ));

        foreach ($inRange as $id) {
            $version = $this->entries[$type->id->toString()][$id] ?? $type->version;

            if ($version !== $type->version) {
                throw RebuildRefused::schemaVersion($type->name, EntryId::fromString($id), VariantKey::shared(), $version, $type->version);
            }
        }

        $this->rebuilt[] = [$range, $access];

        return new ChunkResult($chunk, count($inRange), count($inRange), 0);
    }

    /**
     * The chunk names of the ranges rebuilt, in order.
     *
     * @return list<string>
     */
    public function rebuiltChunks(): array
    {
        return array_map(static fn (array $rebuilt): string => $rebuilt[0]->chunk()->value, $this->rebuilt);
    }

    /**
     * @return list<string>
     */
    private function sorted(TypeDefinition $type): array
    {
        $ids = array_map(strval(...), array_keys($this->entries[$type->id->toString()] ?? []));
        sort($ids, SORT_STRING);

        return $ids;
    }
}
