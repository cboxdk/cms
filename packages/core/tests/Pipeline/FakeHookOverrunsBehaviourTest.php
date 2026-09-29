<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Core\Pipeline\Domain\HookOverruns;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeHookOverruns;
use Override;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/**
 * HookOverrunsBehaviour against the fake the pipeline's action tests use.
 */
final class FakeHookOverrunsBehaviourTest extends TestCase
{
    use HookOverrunsBehaviour;

    #[Override]
    protected function overruns(): HookOverruns
    {
        return new FakeHookOverruns;
    }

    #[Override]
    protected function recorded(HookOverruns $overruns): array
    {
        Assert::assertInstanceOf(FakeHookOverruns::class, $overruns);

        return $overruns->recorded;
    }
}
