<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon;

use Cbox\Cms\Contracts\Attributes\Query as QueryType;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * The addon's query, which its slot fills and pages read their data with.
 */
#[QueryType('approvals.pending', version: 1)]
final readonly class PendingApprovals implements Query
{
    public function __construct(public string $note) {}
}
