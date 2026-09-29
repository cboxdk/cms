<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions;

use Cbox\Cms\Core\Subscriptions\Domain\Pacing;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every Pacing does, run against SystemPacing and FakePacing (GUARDRAILS 9): its milliseconds
 * never move back, and a wait moves them on by at least the wait.
 */
trait PacingBehaviour
{
    abstract protected function pacing(): Pacing;

    #[Test]
    public function it_moves_on_by_at_least_a_wait_and_never_back(): void
    {
        $pacing = $this->pacing();
        $before = $pacing->milliseconds();

        $pacing->sleep(5);
        $after = $pacing->milliseconds();
        $pacing->sleep(0);
        $pacing->sleep(-1);

        Assert::assertGreaterThanOrEqual($before + 5, $after);
        Assert::assertGreaterThanOrEqual($after, $pacing->milliseconds());
    }
}
