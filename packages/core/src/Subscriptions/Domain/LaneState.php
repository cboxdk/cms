<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\BatchProgress;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\LaneReport;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\Parking;

/**
 * What one run of a lane's runner remembers between its batches (PRD 7.7, 7.8): the failed tries
 * of each unit, an event or a released aggregate, and for each queue, a subscription's stream or
 * its releases, the units to hand again at once before a failed one and when the failed one is
 * due. It lives as long as the run: a new run starts counting again, which only means more tries,
 * never a skipped event.
 */
#[Internal]
final class LaneState
{
    /** @var array<string, int> the failed tries in a row of each unit */
    private array $failures = [];

    /** @var array<string, int> per queue, the units before a failed one, handed again at once */
    private array $prefixes = [];

    /** @var array<string, int> per queue, the Pacing time from which its failed unit is tried again */
    private array $dueAt = [];

    private int $batches = 0;

    private int $handled = 0;

    private int $passed = 0;

    private int $failed = 0;

    /** @var list<Parking> */
    private array $parked = [];

    private int $released = 0;

    /** @var list<AggregateKey> */
    private array $releasedWithoutEvent = [];

    private int $busy = 0;

    /**
     * The failed tries in a row of the unit so far.
     */
    public function failures(string $unit): int
    {
        return $this->failures[$unit] ?? 0;
    }

    /**
     * Forgets the unit's failed tries once it was handled or parked.
     */
    public function forget(string $unit): void
    {
        unset($this->failures[$unit]);
    }

    /**
     * Whether the queue has work to try now: units to hand again, or no failed unit waiting.
     */
    public function due(string $queue, int $now): bool
    {
        return isset($this->prefixes[$queue]) || $now >= ($this->dueAt[$queue] ?? $now);
    }

    /**
     * The most units the queue's next batch may take: those before a failed unit, or $limit.
     */
    public function limit(string $queue, int $limit): int
    {
        return $this->prefixes[$queue] ?? $limit;
    }

    /**
     * Records a failed try of the unit at $index of the queue's batch; returns the unit's failed
     * tries in a row. The units before it are handed again at once, the failed one from $dueAt.
     */
    public function failed(string $queue, string $unit, int $index, int $dueAt): int
    {
        $this->failed++;
        $this->failures[$unit] = ($this->failures[$unit] ?? 0) + 1;
        $this->dueAt[$queue] = $dueAt;

        if ($index > 0) {
            $this->prefixes[$queue] = $index;
        } else {
            unset($this->prefixes[$queue]);
        }

        return $this->failures[$unit];
    }

    /**
     * Records a committed batch of the queue. After the units before a failed one, the failed one
     * waits for its time; after any other batch the queue has nothing waiting.
     */
    public function committed(string $queue, BatchProgress $progress): void
    {
        if (isset($this->prefixes[$queue])) {
            unset($this->prefixes[$queue]);
        } else {
            unset($this->dueAt[$queue]);
        }

        $this->batches++;
        $this->handled += $progress->handled;
        $this->passed += $progress->passed + $progress->passedParked;
        $this->released += $progress->released;
        array_push($this->parked, ...$progress->parked);
        array_push($this->releasedWithoutEvent, ...$progress->releasedWithoutEvent);
    }

    public function busy(): void
    {
        $this->busy++;
    }

    /**
     * How long until the earliest failed unit is due, 0 when one is due now or a queue has units to
     * hand again; null when nothing waits.
     */
    public function nextDueIn(int $now): ?int
    {
        if ($this->prefixes !== []) {
            return 0;
        }

        if ($this->dueAt === []) {
            return null;
        }

        return max(0, min($this->dueAt) - $now);
    }

    public function report(Lane $lane, ActorId $actor): LaneReport
    {
        return new LaneReport(
            $lane,
            $actor,
            $this->batches,
            $this->handled,
            $this->passed,
            $this->failed,
            $this->parked,
            $this->released,
            $this->releasedWithoutEvent,
            $this->busy,
        );
    }
}
