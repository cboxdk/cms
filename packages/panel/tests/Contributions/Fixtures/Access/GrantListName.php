<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions\Fixtures\Access;

use Cbox\Cms\Contracts\Attributes\Query as QueryType;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * The query grant.list as the contribution tests register it (PRD 5.10): a query of that name and
 * version alone, with an action on no surface, so the panel registry knows the name the core's nav
 * entry of the grants page requires, while the kernel's own query, its action and its REST route
 * stay out of a world built from the panel's fixtures.
 */
#[QueryType('grant.list', version: 1)]
final readonly class GrantListName implements Query {}
