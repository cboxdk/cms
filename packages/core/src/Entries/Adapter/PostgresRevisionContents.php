<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Entries\Boundary\StoredContent;
use Cbox\Cms\Core\Pipeline\Domain\Dto\RevisionContent;
use Cbox\Cms\Core\Pipeline\Domain\RevisionContents;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\JoinClause;
use Override;
use UnexpectedValueException;

/**
 * The content of a revision on Postgres (PRD 4.1, 6.2 phase 5): one statement by the revision's
 * unique key (entry_id, variant, rev_no), joined to its payload by (revision_id, kind), or for a
 * type whose history is audit-only or none, which keeps no revisions, the head snapshot of the
 * variant when its number is the revision's (`head_snapshots`, by its key (entry_id, variant)), as the app
 * role under the call's actor context, on the default connection, or the one named, and always on
 * the write PDO, inside the command transaction. Row level security hides a revision the actor's
 * regions do not reach, so it reads as absent. The payload is read with StoredContent when the
 * revision's schema version is the type's.
 */
#[Internal]
final readonly class PostgresRevisionContents implements RevisionContents
{
    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function find(EntryId $entry, VariantKey $variant, RevisionNumber $revision, TypeDefinition $type): ?RevisionContent
    {
        $connection = $this->connections->connection($this->connection);

        $row = $type->capabilities->history === History::Full
            ? $connection->table('revisions', 'r')
                ->join('revision_payloads as p', static function (JoinClause $join): void {
                    $join->on('p.revision_id', '=', 'r.revision_id')->on('p.kind', '=', 'r.kind');
                })
                ->where('r.entry_id', $entry->toString())
                ->where('r.variant', $variant->value)
                ->where('r.rev_no', $revision->value)
                ->useWritePdo()
                ->first(['r.schema_version', 'p.content'])
            : $connection->table('head_snapshots')
                ->where('entry_id', $entry->toString())
                ->where('variant', $variant->value)
                ->where('rev_no', $revision->value)
                ->useWritePdo()
                ->first(['schema_version', 'content']);

        if ($row === null) {
            return null;
        }

        $schemaVersion = property_exists($row, 'schema_version') ? $row->schema_version : null;
        $content = property_exists($row, 'content') ? $row->content : null;

        if (! is_int($schemaVersion) || ! is_string($content)) {
            throw new UnexpectedValueException(sprintf('A revision or head snapshot has an integer schema version and a JSON payload, got %s and %s.', get_debug_type($schemaVersion), get_debug_type($content)));
        }

        return new RevisionContent($schemaVersion, $schemaVersion === $type->version ? StoredContent::fields($type, $content) : null);
    }
}
