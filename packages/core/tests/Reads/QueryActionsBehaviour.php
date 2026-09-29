<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryBinding;
use Cbox\Cms\Core\Reads\Domain\QueryActions;
use Cbox\Cms\Core\Reads\Domain\UnknownQuery;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeLibrary;
use Cbox\Cms\Core\Tests\Reads\Probe\ReadProbe;
use Cbox\Cms\Core\Tests\Reads\Probe\ReadProbeAction;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every QueryActions does, run against RegistryQueryActions and FakeQueryActions, so the fake
 * the pipeline's action tests use cannot drift from the registry the application reads
 * (GUARDRAILS 9).
 */
trait QueryActionsBehaviour
{
    /**
     * The implementation under test, knowing only the probe query, probe.read version 2, handled by
     * the given action.
     */
    abstract protected function queryActions(ReadProbeAction $action): QueryActions;

    #[Test]
    public function it_gives_the_query_s_action_with_the_query_s_name_and_version(): void
    {
        $action = new ReadProbeAction(new ProbeLibrary);
        $binding = $this->queryActions($action)->for(new ReadProbe);

        Assert::assertInstanceOf(QueryBinding::class, $binding);
        Assert::assertTrue($binding->query->equals(new CommandName('probe.read')));
        Assert::assertSame(2, $binding->version);
        Assert::assertSame($action, $binding->action);
    }

    #[Test]
    public function it_refuses_a_query_no_action_handles(): void
    {
        $stray = new readonly class implements Query {};

        try {
            $this->queryActions(new ReadProbeAction(new ProbeLibrary))->for($stray);
            Assert::fail('A query no action handles is refused.');
        } catch (UnknownQuery $unknown) {
            Assert::assertStringContainsString('No query action handles the query '.$stray::class, $unknown->getMessage());
        }
    }
}
