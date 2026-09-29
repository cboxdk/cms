<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Core\Pipeline\Domain\AffectedProjections;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeAffectedProjections;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeNoted;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbePublished;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * AffectedProjectionsBehaviour against the fake a commit's tests use.
 */
final class FakeAffectedProjectionsBehaviourTest extends TestCase
{
    use AffectedProjectionsBehaviour;

    #[Override]
    protected function affectedProjections(): AffectedProjections
    {
        return new FakeAffectedProjections([
            ProbePublished::class => [new ProjectionName('search'), new ProjectionName('origin'), new ProjectionName('origin')],
            ProbeNoted::class => [],
        ]);
    }
}
