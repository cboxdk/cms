<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Probe;

use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * The read port RenameProbeAction resolves through: the entries a test put on it, each with the
 * version of the entry and of its shared variant and the head revision. It only reads.
 */
final class ProbeShelf
{
    /** @var array<string, array{AggregateVersion, AggregateVersion, RevisionNumber}> */
    private array $entries = [];

    public function put(EntryId $entry, AggregateVersion $version, AggregateVersion $variant, RevisionNumber $head): void
    {
        $this->entries[$entry->toString()] = [$version, $variant, $head];
    }

    /**
     * @return array{AggregateVersion, AggregateVersion, RevisionNumber}|null
     */
    public function find(EntryId $entry): ?array
    {
        return $this->entries[$entry->toString()] ?? null;
    }
}
