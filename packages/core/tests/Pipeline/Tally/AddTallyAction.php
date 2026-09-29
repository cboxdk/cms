<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Tally;

use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Plan;
use Closure;
use Override;

/**
 * The write action of the test-only command tally.add. resolve() reads each tally's version from
 * the scratch table on the command transaction's connection, and then runs what a test lets happen
 * meanwhile, such as another session changing a row the command read; plan() raises each tally.
 *
 * @implements WriteAction<AddTally, TallyAggregates>
 */
final readonly class AddTallyAction implements WriteAction
{
    /**
     * @param  (Closure(): void)|null  $meanwhile
     */
    public function __construct(
        private TallyTable $table,
        private ?Closure $meanwhile = null,
    ) {}

    /**
     * @param  AddTally  $command
     */
    #[Override]
    public function resolve(Command $command): TallyAggregates
    {
        $reads = [];

        foreach ($this->tallies($command) as $tally) {
            $reads[] = new ReadVersion($tally, $this->table->version($tally));
        }

        if ($this->meanwhile instanceof Closure) {
            ($this->meanwhile)();
        }

        return new TallyAggregates(...$reads);
    }

    /**
     * @param  AddTally  $command
     * @param  TallyAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        return new Plan(...array_map(
            static fn (TallyId $tally): TallyAdded => new TallyAdded($tally, $command->amount),
            $this->tallies($command),
        ));
    }

    /**
     * @return list<TallyId>
     */
    private function tallies(AddTally $command): array
    {
        return $command->next instanceof TallyId ? [$command->tally, $command->next] : [$command->tally];
    }
}
