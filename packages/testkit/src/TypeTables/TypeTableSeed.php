<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\TypeTables;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\EntryId;

/**
 * One released row of a type table, shared variant, as a test writes it before it reads it through
 * a TypeTableReader: the entry, the path of its home node, the actor that owns it, if any, and its
 * fields. Each label of the path is a node's id as 32 hex digits, as the kernel's node paths are,
 * so a harness on a real database can make the nodes from the path.
 */
#[Experimental]
final readonly class TypeTableSeed
{
    public function __construct(
        public EntryId $entry,
        public NodePath $home,
        public FieldValues $fields,
        public ?ActorId $owner = null,
    ) {}
}
