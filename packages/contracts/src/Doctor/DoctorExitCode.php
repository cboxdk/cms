<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Doctor;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The fixed exit codes of `cms:doctor` (PRD 3.3), the only place they are defined. The JSON
 * document of `--json` carries the code and its status name.
 *
 * - Ok (0): every check passed or was skipped.
 * - Unavailable (75, EX_TEMPFAIL from sysexits.h): a dependency could not be reached right now,
 *   and nothing is misconfigured. Trying again later may help.
 * - Violation (78, EX_CONFIG): the configuration is invalid or the runtime contract is broken.
 *   A violation wins over an unavailable dependency, because waiting does not fix it.
 *
 * Every failing check counts, blocking or not; the document says which failures block the kernel.
 * M1 folds these codes into the error catalog.
 */
#[Experimental]
enum DoctorExitCode: int
{
    case Ok = 0;
    case Unavailable = 75;
    case Violation = 78;

    /**
     * The exit code for the results of one run.
     *
     * @param  list<CheckResult>  $results
     */
    public static function for(array $results): self
    {
        $exit = self::Ok;

        foreach ($results as $result) {
            if ($result->failure === FailureKind::Violation) {
                return self::Violation;
            }

            if ($result->failure === FailureKind::Unavailable) {
                $exit = self::Unavailable;
            }
        }

        return $exit;
    }

    /**
     * The name of the exit code in the JSON document: ok, unavailable or violation.
     */
    public function status(): string
    {
        return match ($this) {
            self::Ok => 'ok',
            self::Unavailable => FailureKind::Unavailable->value,
            self::Violation => FailureKind::Violation->value,
        };
    }
}
