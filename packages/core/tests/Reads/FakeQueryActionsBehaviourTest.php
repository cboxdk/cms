<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads;

use Cbox\Cms\Core\Reads\Domain\QueryActions;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryActions;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeQueryBinding;
use Cbox\Cms\Core\Tests\Reads\Probe\ReadProbe;
use Cbox\Cms\Core\Tests\Reads\Probe\ReadProbeAction;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * QueryActionsBehaviour against the fake the pipeline's action tests use.
 */
final class FakeQueryActionsBehaviourTest extends TestCase
{
    use QueryActionsBehaviour;

    #[Override]
    protected function queryActions(ReadProbeAction $action): QueryActions
    {
        return new FakeQueryActions([ReadProbe::class => ProbeQueryBinding::of($action)]);
    }
}
