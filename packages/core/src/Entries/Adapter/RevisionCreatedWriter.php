<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Entries\Boundary\StoredContent;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;
use Override;

/**
 * Writes RevisionCreated in the commit (PRD 4.1, 5.4, 6.2 phase 7, 11.6), for the type the
 * mutation names, as the TypeCatalog gives it, at the type's schema version:
 *
 * 1. The variant's head, when this is the variant's first revision: its row in `variant_heads`,
 *    unreleased, with no draft revision yet, at the context's version. HeadMoved points it at the
 *    revision.
 * 2. The content. A type with full history gets the revision's row in `revisions`, a draft, with
 *    its number, the schema version and the changeset, and its payload in `revision_payloads`. A
 *    type whose history is audit-only or none keeps no revisions (PRD 4.1): the variant's head
 *    snapshot in `head_snapshots` takes the content, the number and the schema version in place.
 * 3. The type table's row, through TypeRows: the draft row for stages draft-release, the released
 *    row for stages none.
 *
 * It returns no event; the head's move tells about the revision. It runs as the app role under the
 * call's actor context, inside the command transaction.
 */
#[Internal]
final readonly class RevisionCreatedWriter implements MutationWriter
{
    /** The kind of a revision a save writes (PRD 4.1); a release writes the published kind. */
    public const string KIND = 'draft';

    /** The release state of a new head (PRD 6.4). */
    public const string UNRELEASED = 'unreleased';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private TypeCatalog $types,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function writes(): string
    {
        return RevisionCreated::class;
    }

    /**
     * @throws LogicException when the installation has no type with the mutation's id, which the pipeline rules out
     */
    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof RevisionCreated) {
            throw new InvalidArgumentException(sprintf('The revision writer writes RevisionCreated, not %s.', $mutation::class));
        }

        $type = $this->types->find($mutation->type)
            ?? throw new LogicException(sprintf('No type of this installation has the id %s; the pipeline refuses such a plan before the commit.', $mutation->type->toString()));
        $db = $this->connections->connection($this->connection);
        $at = Timestamps::of($context->at);
        $content = StoredContent::payload($mutation->fields);

        if ($mutation->revision->value === 1) {
            $db->table('variant_heads')->insert([
                'entry_id' => $mutation->entry->toString(),
                'variant' => $mutation->variant->value,
                'draft_revision_id' => null,
                'published_revision_id' => null,
                'schema_version' => $type->version,
                'release_state' => self::UNRELEASED,
                'workflow_state' => null,
                'next_transition_at' => null,
                'version' => $context->version->value,
                'created_at' => $at,
            ]);
        }

        if ($type->capabilities->history === History::Full) {
            $this->revision($db, $mutation, $type, $context, $content, $at);
        } else {
            $this->snapshot($db, $mutation, $type, $content, $at);
        }

        new TypeRows($db)->write($type, $mutation->entry, $mutation->variant, $mutation->fields);

        return [];
    }

    private function revision(ConnectionInterface $db, RevisionCreated $mutation, TypeDefinition $type, MutationContext $context, string $content, string $at): void
    {
        $revision = $db->table('revisions')->insertGetId([
            'entry_id' => $mutation->entry->toString(),
            'variant' => $mutation->variant->value,
            'rev_no' => $mutation->revision->value,
            'kind' => self::KIND,
            'schema_version' => $type->version,
            'changeset_id' => $context->changesetId->toString(),
            'created_at' => $at,
        ], 'revision_id');

        $db->table('revision_payloads')->insert([
            'revision_id' => $revision,
            'kind' => self::KIND,
            'format_version' => StoredContent::FORMAT_VERSION,
            'content' => $content,
        ]);
    }

    private function snapshot(ConnectionInterface $db, RevisionCreated $mutation, TypeDefinition $type, string $content, string $at): void
    {
        $db->table('head_snapshots')->upsert(
            [[
                'entry_id' => $mutation->entry->toString(),
                'variant' => $mutation->variant->value,
                'rev_no' => $mutation->revision->value,
                'schema_version' => $type->version,
                'format_version' => StoredContent::FORMAT_VERSION,
                'content' => $content,
                'updated_at' => $at,
            ]],
            ['entry_id', 'variant'],
            ['rev_no', 'schema_version', 'format_version', 'content', 'updated_at'],
        );
    }
}
