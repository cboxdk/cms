<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingMutation;

/**
 * A MutationWriter that also writes a run of mutations of its class at once (GUARDRAILS 4.1): the
 * commit hands it every mutation of a plan that follows another of the same class, so a composed
 * plan of many entries, such as a seed chunk, costs a few statements per class, not a few per
 * entry. writeAll() writes exactly what write() would write for each mutation in turn, under the
 * same rules, and returns their events in the order of the mutations.
 */
#[Internal]
interface BatchMutationWriter extends MutationWriter
{
    /**
     * @param  non-empty-list<PendingMutation>  $mutations  of the class writes() names, in plan order
     * @return list<Event>
     */
    public function writeAll(array $mutations): array;
}
