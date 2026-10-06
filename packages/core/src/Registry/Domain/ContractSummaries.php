<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Domain\Dto\ContractSummary;

/**
 * The title and description of the JSON Schema of a command's or a query's contract version
 * (GUARDRAILS 2.2, PRD 13.4): what action.list lists an action by, read from the codecs an exposed
 * surface reads the command or query with. There is none for a contract version no codec reads,
 * which no surface can serve, or for a schema without a title.
 */
#[Internal]
interface ContractSummaries
{
    public function of(ActionKind $kind, CommandName $name, int $version): ?ContractSummary;
}
