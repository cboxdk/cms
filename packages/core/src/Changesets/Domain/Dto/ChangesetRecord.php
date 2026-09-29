<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Changesets\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * What the commit records about one changeset besides its mutations (PRD 5.5, 6.1, 12.12): its id
 * and time, its retention class, the command's name and version, the envelope, and the aggregates
 * the changeset changes, each once, in the order the plan first changes them.
 */
#[Internal]
final readonly class ChangesetRecord
{
    /** @var non-empty-list<AggregateRef> */
    public array $aggregates;

    /**
     * @param  list<AggregateRef>  $aggregates
     */
    public function __construct(
        public ChangesetId $id,
        public DateTimeImmutable $at,
        public RetentionClass $retentionClass,
        public CommandName $command,
        public int $commandVersion,
        public Envelope $envelope,
        array $aggregates,
    ) {
        $byKey = [];

        foreach ($aggregates as $aggregate) {
            $byKey[$aggregate->aggregateKey()] ??= $aggregate;
        }

        if ($byKey === []) {
            throw new InvalidArgumentException('A changeset changes at least one aggregate.');
        }

        $this->aggregates = array_values($byKey);
    }
}
