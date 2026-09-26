<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorExitCode;

/**
 * The result of one cms:doctor run: every check's result in the order they ran, and the exit code
 * they add up to.
 */
#[Experimental]
final readonly class DoctorReport
{
    public DoctorExitCode $exit;

    /**
     * @param  bool  $dev  whether the development checks ran
     * @param  list<CheckResult>  $results
     */
    public function __construct(
        public bool $dev,
        public array $results,
    ) {
        $this->exit = DoctorExitCode::for($results);
    }
}
