<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions;

use Cbox\Cms\Core\Subscriptions\Domain\Pacing;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakePacing;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * PacingBehaviour against the fake the runner's action tests use.
 */
final class FakePacingBehaviourTest extends TestCase
{
    use PacingBehaviour;

    #[Override]
    protected function pacing(): Pacing
    {
        return new FakePacing(1_000);
    }
}
