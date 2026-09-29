<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Tally;

use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Override;
use RuntimeException;

/**
 * The test-only writer of TallyAdded: inserts the tally at version 1 or raises it to the version
 * the context gives, on the app connection inside the command transaction, and returns the event
 * that tells about it. It refuses the tally a test names, after the mutations before it were
 * written, so a test sees them rolled back; and it can be told to return an event at another
 * version than the context's.
 */
final readonly class TallyWriter implements MutationWriter
{
    public function __construct(
        private ?TallyId $refuse = null,
        private int $eventVersionOffset = 0,
    ) {}

    #[Override]
    public function writes(): string
    {
        return TallyAdded::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof TallyAdded) {
            throw new InvalidArgumentException(sprintf('The tally writer writes TallyAdded, not %s.', $mutation::class));
        }

        if ($this->refuse instanceof TallyId && $this->refuse->aggregateKey() === $mutation->tally->aggregateKey()) {
            throw new RuntimeException(sprintf('The tally writer refuses the tally %s.', $mutation->tally->toString()));
        }

        DB::connection()->statement(
            'insert into tally_counters (id, version, total) values (?, ?, ?) on conflict (id) do update set version = excluded.version, total = tally_counters.total + excluded.total',
            [$mutation->tally->toString(), $context->version->value, $mutation->amount],
        );

        return [new TallyRaised($mutation->tally, $context->version->value + $this->eventVersionOffset)];
    }
}
