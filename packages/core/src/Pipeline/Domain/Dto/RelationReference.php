<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\TypeName;

/**
 * One target of a relation field in a revision a plan creates (PRD 5.11): the entry it names, the
 * types the field allows it to have, and the field's path in the command, such as
 * `fields.related`. `item` is the target's index in a relation that holds several, and null for a
 * relation that holds one.
 */
#[Internal]
final readonly class RelationReference
{
    /**
     * @param  non-empty-list<TypeName>  $targets
     */
    public function __construct(
        public EntryId $entry,
        public array $targets,
        public FieldPath $path,
        public ?int $item = null,
    ) {}

    /**
     * Whether the field allows a target of the type.
     */
    public function allows(TypeName $type): bool
    {
        return array_any($this->targets, static fn (TypeName $target): bool => $target->equals($type));
    }
}
