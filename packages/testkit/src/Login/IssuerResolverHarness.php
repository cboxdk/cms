<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Login\IssuerPin;
use Cbox\Cms\Contracts\Identity\Login\IssuerResolver;

/**
 * What the shared suite IssuerResolverContract needs: a resolver configured with exactly the given
 * pins. A harness for a real resolver writes them where that resolver reads its connections, such
 * as the environment's configuration.
 */
#[Experimental]
interface IssuerResolverHarness
{
    public function resolver(IssuerPin ...$pins): IssuerResolver;
}
