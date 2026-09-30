<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\ReadModels\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\ReadModels\Domain\Dto\ChunkResult;

/**
 * Where a rebuild reads the heads of a type and writes its table (PRD 4.1, 11.6, invariant 22).
 * Each call runs in transactions of its own, each under the access context, so row level security
 * decides which entries it sees and writes, and each shorter than 2 seconds (GUARDRAILS 4.1).
 */
#[Internal]
interface ReadModelStore
{
    /**
     * The type's entries the context reaches, as ranges of at most $chunkSize entries each, in id
     * order, with no entry in two ranges. Empty when it reaches none.
     *
     * @return list<EntryRange>
     */
    public function plan(TypeDefinition $type, AccessContext $access, int $chunkSize): array;

    /**
     * Recomputes the type table's rows of the entries in the range, in one transaction: each
     * variant's rows are written from its head's revisions for a type with full history, or from
     * its head snapshot otherwise, with the writer the commands use, and nothing else of those
     * entries is left in the table. It locks the heads it reads, so a command on one of them waits
     * for it, and it sees what a command committed before.
     *
     * @throws RebuildRefused with rebuild_schema_version_unsupported when a payload was written under another schema version than the type's; nothing of the range is changed
     */
    public function rebuild(TypeDefinition $type, AccessContext $access, EntryRange $range): ChunkResult;
}
