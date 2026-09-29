<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Probe;

use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\EntryCreated;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use Override;

/**
 * The write action of the test-only command probe.rename. resolve() reads the entry through the
 * shelf and plan() turns it into mutations; both record the arguments they got, so a test sees
 * that the pipeline hands them nothing but the command and the aggregates. A test may add reads,
 * or a mutation of an aggregate resolve() did not read.
 *
 * @implements WriteAction<RenameProbe, ProbeAggregates>
 */
final readonly class RenameProbeAction implements WriteAction
{
    /**
     * @param  list<ReadVersion>  $extraReads
     */
    public function __construct(
        private ProbeShelf $shelf,
        private ProbeCalls $calls = new ProbeCalls,
        private array $extraReads = [],
        private ?Plan $unreadPlan = null,
    ) {}

    /**
     * @param  RenameProbe  $command
     */
    #[Override]
    public function resolve(Command $command): ProbeAggregates
    {
        $this->calls->record('resolve', func_get_args());
        $stored = $this->shelf->find($command->entry);

        return $stored === null
            ? new ProbeAggregates($command->entry, null, null, null, $this->extraReads)
            : new ProbeAggregates($command->entry, $stored[0], $stored[1], $stored[2], $this->extraReads);
    }

    /**
     * @param  RenameProbe  $command
     * @param  ProbeAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        $this->calls->record('plan', func_get_args());
        $shared = VariantKey::shared();

        if (! $aggregates->head instanceof RevisionNumber) {
            $plan = new Plan(
                new EntryCreated($command->entry, $command->type, $command->home),
                new RevisionCreated($command->entry, $command->type, $shared, RevisionNumber::first(), $command->fields),
                new HeadMoved($command->entry, $shared, null, RevisionNumber::first()),
            );
        } else {
            $next = $aggregates->head->next();
            $plan = new Plan(
                new RevisionCreated($command->entry, $command->type, $shared, $next, $command->fields),
                new HeadMoved($command->entry, $shared, $aggregates->head, $next),
            );
        }

        return $this->unreadPlan instanceof Plan ? $plan->then($this->unreadPlan) : $plan;
    }
}
