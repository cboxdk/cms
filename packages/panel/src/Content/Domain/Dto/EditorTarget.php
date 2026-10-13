<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Content\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Editors\TypeEditor;
use Cbox\Cms\Contracts\Ids\EntryId;

/**
 * What a request of the entry editor asks for (PRD 5.4, 13.4): the type the address names, as the
 * installation's editor descriptors have it, and the entry it edits, or null for a new entry.
 */
#[Internal]
final readonly class EditorTarget
{
    public function __construct(
        public TypeEditor $type,
        public ?EntryId $entry,
    ) {}
}
