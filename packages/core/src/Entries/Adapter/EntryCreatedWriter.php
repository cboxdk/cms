<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\EntryCreated;
use Cbox\Cms\Core\Entries\Domain\Events\EntryCreated as EntryCreatedEvent;
use Cbox\Cms\Core\Entries\Domain\Events\EntryCreatedV1;
use Cbox\Cms\Core\Pipeline\Domain\BatchMutationWriter;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingMutation;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;

/**
 * Writes EntryCreated in the commit (PRD 5.4, 6.2 phase 7): the entry's row in `entries`, active,
 * at the context's version and time, with no owner, because no capability of blueprint v1 makes a
 * type owned (PRD 5.15); a run of them in one statement. It returns entry.created. It runs as the
 * app role under the call's actor context, so the row's home must be a node the actor's regions
 * reach (PRD 5.10).
 */
#[Internal]
final readonly class EntryCreatedWriter implements BatchMutationWriter
{
    /** The lifecycle state of a new entry (PRD 6.4). */
    public const string ACTIVE = 'active';

    /** The most rows one insert carries, well below Postgres' 65535 bindings. */
    public const int ROWS_PER_STATEMENT = 1000;

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function writes(): string
    {
        return EntryCreated::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        return $this->writeAll([new PendingMutation($mutation, $context)]);
    }

    /**
     * The entries' rows in one statement per ROWS_PER_STATEMENT.
     */
    #[Override]
    public function writeAll(array $mutations): array
    {
        $rows = [];
        $events = [];

        foreach ($mutations as $pending) {
            $mutation = $pending->mutation;

            if (! $mutation instanceof EntryCreated) {
                throw new InvalidArgumentException(sprintf('The entry writer writes EntryCreated, not %s.', $mutation::class));
            }

            $rows[] = [
                'id' => $mutation->entry->toString(),
                'type_id' => $mutation->type->toString(),
                'home_node_id' => $mutation->home->toString(),
                'owner_actor_id' => null,
                'lifecycle' => self::ACTIVE,
                'version' => $pending->context->version->value,
                'created_at' => Timestamps::of($pending->context->at),
            ];
            $events[] = new EntryCreatedEvent($pending->context->version->value, new EntryCreatedV1($mutation->entry, $mutation->type, $mutation->home));
        }

        $table = $this->connections->connection($this->connection)->table('entries');

        foreach (array_chunk($rows, self::ROWS_PER_STATEMENT) as $chunk) {
            $table->insert($chunk);
        }

        return $events;
    }
}
