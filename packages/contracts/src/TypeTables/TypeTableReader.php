<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\TypeTables;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\AccessContext;

/**
 * Reads pages of a type table (PRD 5.10, 8.8, 11.6, 11.12), the port the generated query builder
 * of every type runs on. The kernel knows no type by name (GUARDRAILS 2.4): it learns the type's
 * columns and which fields are filterable and sortable from the TypeCatalog.
 *
 * page() reads the released rows of the query's variant that match every filter, sorted by the
 * order and then by the entry id, after the cursor, at most the query's limit. It always adds the
 * explicit access predicate of the context (PRD 5.10: row level security is the backstop, not the
 * primary filter): a row is read when the context's actor owns it, or when one of the context's
 * regions reaches its home node. A context without an actor and without regions reads no row.
 *
 * It refuses, with InvalidTypeTableQuery and before it reads, a type the catalog does not have, a
 * column that is no field's, a filter on a field its blueprint does not declare filterable and an
 * order by a field its blueprint does not declare sortable (PRD 8.8). A page costs the same number
 * of queries whatever the number of matching rows (GUARDRAILS 4.1).
 */
#[Experimental]
interface TypeTableReader
{
    /**
     * @throws InvalidTypeTableQuery
     */
    public function page(TypeTableQuery $query, AccessContext $access): TypeTablePage;
}
