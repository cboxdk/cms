<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;

/**
 * The projections a changeset affects (PRD 7.6, 8.4): the pending status of each projection whose
 * subscriber receives one of the changeset's events and acknowledges on the receipt. The commit
 * (PRD 6.2 phase 7) stores the changeset's receipt with exactly these, so a caller that waits for a
 * level past commit waits for the projections that will acknowledge, and for no other.
 */
#[Internal]
interface AffectedProjections
{
    /**
     * The pending projections of the events, each once, sorted by name. An event that no
     * subscriber receives, or whose subscribers acknowledge no projection, adds none.
     *
     * @param  list<Event>  $events
     * @return list<ProjectionStatus>
     */
    public function pendingFor(array $events): array;
}
