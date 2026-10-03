<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon;

use Cbox\Cms\Contracts\Attributes\Query as QueryType;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * The addon's query without input, which its page reads its data with.
 */
#[QueryType('approvals.queue', version: 1)]
final readonly class ListApprovalQueue implements Query {}
