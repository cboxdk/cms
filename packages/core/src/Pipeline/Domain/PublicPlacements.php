<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Core\Pipeline\Domain\Dto\EntryPlacements;

/**
 * Whether an entry is shown to the public, what the pipeline weighs before it lets an agent save an
 * entry of a type with stages none (invariant 18): such a save writes the released row, so it
 * changes what the public reads wherever a placement of the entry is visible now or later. It
 * reads every placement of the entry, past the actor's regions, in the command transaction and
 * takes no lock; the commit locks what was read and checks its version.
 */
#[Internal]
interface PublicPlacements
{
    public function of(EntryId $entry): EntryPlacements;
}
