<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions\Fixtures;

use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Subscribers\Delivery;
use Closure;
use RuntimeException;

/**
 * What the runner's test subscriber was handed, and how it behaves: it fails for the aggregates the
 * test marks broken, and runs the test's step before it records or fails, such as a write to a
 * table or a move of the pacing.
 */
final class SubscriberJournal
{
    /** @var list<string> each call as "<aggregate id>@<version> try <attempt>[ release]" */
    private array $calls = [];

    /** @var list<Delivery> */
    private array $deliveries = [];

    /** @var array<string, true> the aggregate ids whose events fail */
    private array $broken = [];

    /** @var (Closure(StoredEvent, Delivery): void)|null */
    private ?Closure $step = null;

    public function break(string $aggregateId): void
    {
        $this->broken[$aggregateId] = true;
    }

    public function fix(string $aggregateId): void
    {
        unset($this->broken[$aggregateId]);
    }

    /**
     * @param  Closure(StoredEvent, Delivery): void  $step
     */
    public function each(Closure $step): void
    {
        $this->step = $step;
    }

    public function record(StoredEvent $event, Delivery $delivery): void
    {
        if ($this->step instanceof Closure) {
            ($this->step)($event, $delivery);
        }

        $id = $event->aggregate->id->value;
        $this->calls[] = sprintf('%s@%d try %d%s', $id, $event->aggregate->version, $delivery->attempt, $delivery->release ? ' release' : '');
        $this->deliveries[] = $delivery;

        if (isset($this->broken[$id])) {
            throw new RuntimeException(sprintf('The subscriber cannot handle %s yet.', $id));
        }
    }

    /**
     * @return list<string>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @return list<Delivery>
     */
    public function deliveries(): array
    {
        return $this->deliveries;
    }
}
