<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredEntry;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Entries\Domain\EntryReader;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Override;
use UnexpectedValueException;

/**
 * The entry commands' reads on Postgres (PRD 5.4, 6.2 phase 1), as the app role under the call's
 * actor context, on the default connection, or the one named, and always on the write PDO, inside
 * the command transaction.
 *
 * entry() is one statement by key: the entry, the head of the variant, the number of the head's
 * draft revision, from `revisions` for a type with full history and from `head_snapshots` for one
 * whose history is audit-only or none, which keeps no revisions, the highest number of the
 * variant's revisions, by the unique key (entry_id, variant, rev_no), and the number of the
 * released revision when the head's release state is released. node() reads one node's version.
 * Row level security hides what the actor's regions do not reach, so it reads as absent.
 */
#[Internal]
final readonly class PostgresEntryReader implements EntryReader
{
    /** The release state of a head whose published revision readers see (PRD 6.4). */
    public const string RELEASED = 'released';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function entry(EntryId $entry, VariantKey $variant): ?StoredEntry
    {
        $row = $this->db()
            ->table('entries', 'e')
            ->leftJoin('variant_heads as h', static function (JoinClause $join) use ($variant): void {
                $join->on('h.entry_id', '=', 'e.id')->where('h.variant', '=', $variant->value);
            })
            ->leftJoin('revisions as r', 'r.revision_id', '=', 'h.draft_revision_id')
            ->leftJoin('revisions as p', static function (JoinClause $join): void {
                $join->on('p.revision_id', '=', 'h.published_revision_id')->where('h.release_state', '=', self::RELEASED);
            })
            ->leftJoin('head_snapshots as s', static function (JoinClause $join): void {
                $join->on('s.entry_id', '=', 'h.entry_id')->on('s.variant', '=', 'h.variant');
            })
            ->where('e.id', $entry->toString())
            ->select([
                'e.type_id',
                'e.home_node_id',
                'e.version as entry_version',
                'h.version as head_version',
                'r.rev_no as revision_number',
                's.rev_no as snapshot_number',
                'p.rev_no as released_number',
            ])
            ->selectSub(
                static fn (Builder $latest): Builder => $latest
                    ->from('revisions', 'l')
                    ->whereColumn('l.entry_id', 'h.entry_id')
                    ->whereColumn('l.variant', 'h.variant')
                    ->selectRaw('max(l.rev_no)'),
                'latest_number',
            )
            ->useWritePdo()
            ->first();

        if ($row === null) {
            return null;
        }

        $headVersion = $this->integerOrNull($row, 'head_version');
        $revision = $this->integerOrNull($row, 'revision_number') ?? $this->integerOrNull($row, 'snapshot_number');
        $latest = $this->integerOrNull($row, 'latest_number') ?? $revision;
        $released = $this->integerOrNull($row, 'released_number');

        if (($headVersion === null) !== ($revision === null)) {
            throw new UnexpectedValueException(sprintf(
                'The head of the variant %s of the entry %s has no current revision, or a revision without a head; every head is written with its first revision.',
                $variant->value,
                $entry->toString(),
            ));
        }

        return new StoredEntry(
            $entry,
            TypeId::fromString($this->text($row, 'type_id')),
            NodeId::fromString($this->text($row, 'home_node_id')),
            new AggregateVersion($this->integer($row, 'entry_version')),
            $headVersion === null || $revision === null || $latest === null ? null : new StoredHead(
                new AggregateVersion($headVersion),
                new RevisionNumber($revision),
                new RevisionNumber($latest),
                $released === null ? null : new RevisionNumber($released),
            ),
        );
    }

    #[Override]
    public function node(NodeId $node): ?AggregateVersion
    {
        $version = $this->db()
            ->table('nodes')
            ->where('id', $node->toString())
            ->useWritePdo()
            ->value('version');

        if ($version === null) {
            return null;
        }

        if (! is_int($version)) {
            throw new UnexpectedValueException(sprintf('The version of a node is an integer, got %s.', get_debug_type($version)));
        }

        return new AggregateVersion($version);
    }

    private function text(object $row, string $column): string
    {
        $value = property_exists($row, $column) ? $row->{$column} : null;

        return is_string($value) ? $value : throw new UnexpectedValueException(sprintf('The column %s of an entry is text, got %s.', $column, get_debug_type($value)));
    }

    private function integer(object $row, string $column): int
    {
        return $this->integerOrNull($row, $column) ?? throw new UnexpectedValueException(sprintf('The column %s of an entry is not null.', $column));
    }

    private function integerOrNull(object $row, string $column): ?int
    {
        $value = property_exists($row, $column) ? $row->{$column} : null;

        if ($value !== null && ! is_int($value)) {
            throw new UnexpectedValueException(sprintf('The column %s of an entry is an integer, got %s.', $column, get_debug_type($value)));
        }

        return $value;
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}
