<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Maintenance\Fakes;

use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Maintenance\Domain\InstallationOperator;
use Override;

/**
 * An installation whose operator the test sets, or the FakeOperatorGenesis writes; none by default.
 */
final class FakeInstallationOperator implements InstallationOperator
{
    /** How often find() was asked. */
    public int $reads = 0;

    public function __construct(public ?ActorId $operator = null) {}

    #[Override]
    public function find(): ?ActorId
    {
        $this->reads++;

        return $this->operator;
    }
}
