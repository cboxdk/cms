<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor\Fakes;

use Cbox\Cms\Core\Doctor\Domain\Dto\ParkedCount;
use Cbox\Cms\Core\Doctor\Domain\Dto\SubscriptionLag;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\EventLogProbe;

/**
 * An event log whose subscriptions and parkings the test sets; by default no subscription and
 * nothing parked.
 */
final class FakeEventLogProbe implements EventLogProbe
{
    /**
     * @param  list<SubscriptionLag>  $subscriptions
     * @param  list<ParkedCount>  $parkedCounts
     */
    public function __construct(
        public array $subscriptions = [],
        public array $parkedCounts = [],
        public ?ProbeFailed $failure = null,
    ) {}

    public function lag(): array
    {
        if ($this->failure instanceof ProbeFailed) {
            throw $this->failure;
        }

        return $this->subscriptions;
    }

    public function parked(): array
    {
        if ($this->failure instanceof ProbeFailed) {
            throw $this->failure;
        }

        return $this->parkedCounts;
    }
}
