<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Actions;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Domain\DoctorChecks;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorReport;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorRunOptions;
use Throwable;

/**
 * cms:doctor as the surfaces call it (PRD 3.3, 4.2, 13.2): runs the checks in their order and
 * adds up the exit code.
 *
 * A check whose requirement did not pass is skipped, and the skip names the requirement. A check
 * that breaks its contract, by throwing or by answering for another id, fails as a violation with
 * CODE_CRASHED, so the doctor always gives a complete report.
 */
#[Experimental]
final readonly class RunDoctor
{
    public const string CODE_CRASHED = 'doctor_check_crashed';

    public function __construct(private DoctorChecks $checks) {}

    public function run(DoctorRunOptions $options): DoctorReport
    {
        /** @var array<string, CheckResult> $results */
        $results = [];

        foreach ($this->checks->for($options) as $check) {
            $results[$check->id()->value] = $this->result($check, $results);
        }

        return new DoctorReport($options->dev, array_values($results));
    }

    /**
     * @param  array<string, CheckResult>  $earlier
     */
    private function result(DoctorCheck $check, array $earlier): CheckResult
    {
        $id = $check->id();
        $blocking = $check->blocking();

        foreach ($check->requires() as $required) {
            $result = $earlier[$required->value] ?? null;

            if (! $result instanceof CheckResult || ! $result->passed()) {
                return CheckResult::skip(
                    $id,
                    $blocking,
                    sprintf('Not run, because it needs %s to pass first.', $required->value),
                    sprintf('%s did not pass.', $required->value),
                );
            }
        }

        try {
            $result = $check->run();
        } catch (Throwable $thrown) {
            return $this->crashed($id, $blocking, sprintf('It threw %s: %s', $thrown::class, $thrown->getMessage()));
        }

        if (! $result->id->equals($id) || $result->blocking !== $blocking) {
            return $this->crashed($id, $blocking, sprintf(
                'It answered for %s with blocking %s instead of for itself.',
                $result->id->value,
                $result->blocking ? 'true' : 'false',
            ));
        }

        return $result;
    }

    private function crashed(CheckId $id, bool $blocking, string $cause): CheckResult
    {
        return CheckResult::fail(
            $id,
            $blocking,
            FailureKind::Violation,
            self::CODE_CRASHED,
            'The check did not finish, so the doctor cannot say whether this part is in order.',
            $cause,
            'This is a bug in the check. Report it with the cause, and run cms:doctor again after updating.',
        );
    }
}
