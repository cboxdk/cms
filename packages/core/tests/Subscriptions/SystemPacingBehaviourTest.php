<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions;

use Cbox\Cms\Core\Subscriptions\Adapter\SystemPacing;
use Cbox\Cms\Core\Subscriptions\Domain\Pacing;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * PacingBehaviour against the monotonic clock and real waits the runner uses.
 */
final class SystemPacingBehaviourTest extends TestCase
{
    use PacingBehaviour;

    #[Override]
    protected function pacing(): Pacing
    {
        return new SystemPacing;
    }
}
