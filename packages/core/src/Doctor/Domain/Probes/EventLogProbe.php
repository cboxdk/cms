<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Probes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\Dto\ParkedCount;
use Cbox\Cms\Core\Doctor\Domain\Dto\SubscriptionLag;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;

/**
 * The event log as the subscriptions see it (PRD 7.6 to 7.8, 7.12), read as the app role on the
 * primary: how far each registered subscription is behind, and what it has parked.
 */
#[Internal]
interface EventLogProbe
{
    /**
     * Every subscription of the registry cache, in registry order, with its oldest unhandled event.
     *
     * @return list<SubscriptionLag>
     *
     * @throws ProbeFailed when the registry cache or the event log cannot be read
     */
    public function lag(): array;

    /**
     * The subscriptions that have parked aggregates not yet released, by name, each with its count.
     *
     * @return list<ParkedCount>
     *
     * @throws ProbeFailed when the event log cannot be read
     */
    public function parked(): array;
}
