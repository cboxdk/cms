<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Probe;

use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeRevisionContents;

/**
 * The read port RenameProbeAction resolves through: the entries a test put on it, each with the
 * version of the entry and of its shared variant and the head revision. It only reads. The head
 * revision's fields, none unless a test gives some, are stored in revisions(), the
 * RevisionContents the world's pipeline reads the fields a caller may not read from.
 */
final class ProbeShelf
{
    /** @var array<string, array{AggregateVersion, AggregateVersion, RevisionNumber}> */
    private array $entries = [];

    private readonly FakeRevisionContents $revisions;

    public function __construct()
    {
        $this->revisions = new FakeRevisionContents;
    }

    public function put(EntryId $entry, AggregateVersion $version, AggregateVersion $variant, RevisionNumber $head, FieldValues $fields = new FieldValues): void
    {
        $this->entries[$entry->toString()] = [$version, $variant, $head];
        $this->revisions->with($entry, VariantKey::shared(), $head, ProbeType::definition()->version, $fields);
    }

    /**
     * The stored head revisions of the entries put on the shelf.
     */
    public function revisions(): FakeRevisionContents
    {
        return $this->revisions;
    }

    /**
     * @return array{AggregateVersion, AggregateVersion, RevisionNumber}|null
     */
    public function find(EntryId $entry): ?array
    {
        return $this->entries[$entry->toString()] ?? null;
    }
}
