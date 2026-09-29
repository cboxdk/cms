<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;

/**
 * The authorize phase of the query pipeline (PRD 6.2): whether the call's AccessContext may run the
 * read, by its name and its query. It runs after the actor context is set and before the budget is
 * checked, and it only looks: it writes nothing, and a refusal carries the reason in plain
 * language, which the pipeline answers as unauthorized. What rows the read reaches is not its
 * question; row level security under the context decides that (PRD 5.10).
 *
 * The kernel's implementation comes with the rule for which roles' permissions allow which commands
 * and reads, together with the CommandAuthorizer's (PROGRESS.md, "Til review af Sylvester"); until
 * one is bound, the container cannot build the QueryPipeline.
 */
#[Internal]
interface QueryAuthorizer
{
    public function authorize(AccessContext $access, CommandName $query, Query $input): Authorization;
}
