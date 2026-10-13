<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\TypeId;

/**
 * The types of the entries a read found, by entry: an entry the read did not find, or one the
 * actor cannot read, has none.
 */
#[Internal]
final readonly class EntryTypes
{
    /**
     * @param  array<string, TypeId>  $types  by the entry's id
     */
    public function __construct(private array $types = []) {}

    public function of(EntryId $entry): ?TypeId
    {
        return $this->types[$entry->toString()] ?? null;
    }

    /**
     * The types with the entries of another read added.
     */
    public function with(EntryId $entry, TypeId $type): self
    {
        return new self([...$this->types, $entry->toString() => $type]);
    }
}
