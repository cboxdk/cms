<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Core\Entries\Domain\Dto\LocaleFields;

/**
 * One entry of a seeded data set: its id, type and home node, the fields of the first revision of
 * its shared variant, whether that revision, and each language's, is released in the same
 * changeset, and the first revision of each language's variant for a type with localization
 * variants (PRD 10.1), none for a type whose shared variant holds every field.
 */
#[Experimental]
final readonly class SeededEntry
{
    /**
     * @param  list<LocaleFields>  $languages  each locale once
     */
    public function __construct(
        public EntryId $entry,
        public TypeId $type,
        public NodeId $home,
        public FieldValues $fields,
        public bool $release,
        public array $languages = [],
    ) {}
}
