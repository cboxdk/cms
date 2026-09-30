<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\NodeId;

/**
 * The nodes a seed run spreads its entries over: every node the actor's regions reach that can be
 * the home of an entry, that is every node but a mount, sorted by id. Node commands come with block
 * B2, so the seeder uses the structure that exists.
 *
 * It reads in a transaction of its own under the actor's context, which it ends before it returns.
 */
#[Internal]
interface SeedTargets
{
    /**
     * @return list<NodeId>
     */
    public function nodes(AccessContext $access): array;
}
