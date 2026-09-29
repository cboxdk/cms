<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Core\Pipeline\Domain\WriteActions;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeWriteActions;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeBinding;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbe;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeAction;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * WriteActionsBehaviour against the fake the pipeline's action tests use.
 */
final class FakeWriteActionsBehaviourTest extends TestCase
{
    use WriteActionsBehaviour;

    #[Override]
    protected function writeActions(RenameProbeAction $action): WriteActions
    {
        return new FakeWriteActions([RenameProbe::class => ProbeBinding::of($action)]);
    }
}
