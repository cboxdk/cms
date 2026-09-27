<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor\Fakes;

use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use LogicException;
use Override;

/**
 * An addon's check whose blocking() throws, so the doctor cannot tell whether a failure of it
 * blocks the kernel from starting.
 */
final readonly class UndecidedBlockingCheck implements DoctorCheck
{
    #[Override]
    public function id(): CheckId
    {
        return new CheckId('addon.undecided');
    }

    #[Override]
    public function blocking(): bool
    {
        throw new LogicException('The addon has not decided whether this check blocks.');
    }

    #[Override]
    public function requires(): array
    {
        return [];
    }

    #[Override]
    public function run(): CheckResult
    {
        return CheckResult::pass($this->id(), false, 'The check addon.undecided passes.');
    }
}
