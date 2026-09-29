<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres\QueryProbe;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Illuminate\Database\DatabaseManager;
use Override;
use RuntimeException;

/**
 * The test-only query action of probe.see_entries: it reads, on the default connection the read
 * transaction runs on, the backend's process id, the actor context the policies see and the
 * entries row level security lets it read, and records them in SeenContext.
 *
 * @implements QueryAction<SeeEntries, SeenEntries>
 */
#[Action(handles: SeeEntries::class)]
final readonly class SeeEntriesAction implements QueryAction
{
    public function __construct(
        private DatabaseManager $database,
        private SeenContext $seen,
    ) {}

    #[Override]
    public function cost(Query $query): QueryCost
    {
        return new QueryCost(1);
    }

    /**
     * @param  SeeEntries  $query
     */
    #[Override]
    public function handle(Query $query): SeenEntries
    {
        $db = $this->database->connection();
        [$pid, $principal, $actor] = explode(' ', StorageTables::texts($db, "select concat_ws(' ', pg_backend_pid(), coalesce(cms_access_context(), '-'), coalesce(cms_access_actor()::text, '-')) as value")[0]);
        $entries = array_map(
            static function (string $row): ReadContent {
                [$entry, $node, $type] = explode(' ', $row);

                return new ReadContent(EntryId::fromString($entry), NodeId::fromString($node), TypeId::fromString($type));
            },
            StorageTables::texts($db, "select concat_ws(' ', id, home_node_id, type_id) as value from entries order by id"),
        );

        $this->seen->reads[] = [
            'pid' => (int) $pid,
            'principal' => $principal,
            'actor' => $actor,
            'entries' => array_map(static fn (ReadContent $entry): string => $entry->entry->toString(), $entries),
        ];

        if ($query->fail) {
            throw new RuntimeException('The probe read broke halfway.');
        }

        return new SeenEntries($entries);
    }
}
