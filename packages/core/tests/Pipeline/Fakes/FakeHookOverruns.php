<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Fakes;

use Cbox\Cms\Core\Pipeline\Domain\Dto\HookOverrun;
use Cbox\Cms\Core\Pipeline\Domain\HookOverruns;
use Override;

/**
 * Keeps every overrun it is given, in order.
 */
final class FakeHookOverruns implements HookOverruns
{
    /** @var list<HookOverrun> */
    public array $recorded = [];

    #[Override]
    public function record(HookOverrun $overrun): void
    {
        $this->recorded[] = $overrun;
    }
}
