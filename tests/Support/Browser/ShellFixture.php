<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Browser;

use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\NodeId;

/**
 * The world of the shell's browser test (tests/Browser/Panel/ShellTest.php): the member of staff
 * with the grant, and the site's root the grant holds on. It lives here, not in the Pest file,
 * because a class declared in a test file does not comply with PSR-4 and fails the optimized
 * autoloader (AutoloadPsr4Test).
 */
final readonly class ShellFixture
{
    public function __construct(
        public ActorId $actor,
        public NodeId $root,
    ) {}
}
