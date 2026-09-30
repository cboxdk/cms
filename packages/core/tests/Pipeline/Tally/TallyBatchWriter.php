<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Tally;

use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Core\Pipeline\Domain\BatchMutationWriter;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingMutation;
use InvalidArgumentException;
use Override;

/**
 * The test-only writer of TallyAdded that can write a run at once, as the commit's batch path
 * needs: it notes each call, writes through TallyWriter, and can return an event about the wrong
 * version of the last tally of a run.
 */
final class TallyBatchWriter implements BatchMutationWriter
{
    /** @var list<list<string>> the tallies of each call, a single write() as a run of one */
    public array $calls = [];

    public function __construct(private readonly int $lastEventVersionOffset = 0) {}

    #[Override]
    public function writes(): string
    {
        return TallyAdded::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        $this->calls[] = [$this->key($mutation)];

        return new TallyWriter()->write($mutation, $context);
    }

    #[Override]
    public function writeAll(array $mutations): array
    {
        $this->calls[] = array_map(fn (PendingMutation $pending): string => $this->key($pending->mutation), $mutations);
        $events = [];

        foreach ($mutations as $index => $pending) {
            $offset = $index === count($mutations) - 1 ? $this->lastEventVersionOffset : 0;
            array_push($events, ...new TallyWriter(eventVersionOffset: $offset)->write($pending->mutation, $pending->context));
        }

        return $events;
    }

    private function key(Mutation $mutation): string
    {
        return $mutation instanceof TallyAdded ? $mutation->tally->toString() : throw new InvalidArgumentException('A tally writer writes TallyAdded.');
    }
}
