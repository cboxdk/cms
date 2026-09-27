<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor\Fakes;

use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Override;

/**
 * A check that always passes, with a fixed id, blocking and requirements. Its subclasses have no
 * constructor arguments, so the container builds them from a class name in cbox-cms.doctor.checks or
 * cbox-cms.doctor.dev_checks, as it builds an application's or addon's check.
 */
abstract readonly class FixedDoctorCheck implements DoctorCheck
{
    /**
     * @param  list<string>  $requires
     */
    public function __construct(private string $id, private bool $blocking, private array $requires) {}

    #[Override]
    public function id(): CheckId
    {
        return new CheckId($this->id);
    }

    #[Override]
    public function blocking(): bool
    {
        return $this->blocking;
    }

    #[Override]
    public function requires(): array
    {
        return array_map(static fn (string $id): CheckId => new CheckId($id), $this->requires);
    }

    #[Override]
    public function run(): CheckResult
    {
        return CheckResult::pass($this->id(), $this->blocking, sprintf('The fixed check %s passes.', $this->id));
    }
}
