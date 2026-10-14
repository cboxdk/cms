<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Results\FieldPath;

/**
 * The target of a link field in a revision a plan creates (B2-T76): the entry or the node it
 * names, of any type, and the path of the target in the command, such as `fields.source.entry`.
 */
#[Internal]
final readonly class LinkReference
{
    public function __construct(
        public EntryId|NodeId $target,
        public FieldPath $path,
    ) {}
}
