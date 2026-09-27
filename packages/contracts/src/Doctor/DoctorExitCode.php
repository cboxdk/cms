<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Doctor;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The fixed exit codes of `cms:doctor` (PRD 3.3), the only place they are defined. The JSON
 * document of `--json` carries the code and its status name.
 *
 * - Ok (0): every check passed or was skipped. The kernel may start and is ready.
 * - Unavailable (75, EX_TEMPFAIL from sysexits.h): a blocking check could not reach a dependency
 *   right now, and no blocking check found a violation. Trying again later may help.
 * - Violation (78, EX_CONFIG): a blocking check found the configuration invalid or the runtime
 *   contract broken. A violation wins over an unavailable dependency, because waiting does not fix
 *   it.
 * - NotReady (79): no blocking check failed, but at least one check that only affects readiness
 *   did. The kernel may start, but is not ready. 79 lies just above the sysexits range of 64 to 78,
 *   so it has no other meaning there, and it cannot be mistaken for 1 or 2.
 *
 * When a blocking check fails, the blocking failures alone decide the code. A probe or a deploy
 * guard can rely on the code: the kernel may start at 0 and 79, and is ready only at 0. The
 * document lists every failure, blocking or not. M1 folds these codes into the error catalog.
 */
#[Experimental]
enum DoctorExitCode: int
{
    case Ok = 0;
    case Unavailable = 75;
    case Violation = 78;
    case NotReady = 79;

    /**
     * The exit code for the results of one run.
     *
     * @param  list<CheckResult>  $results
     */
    public static function for(array $results): self
    {
        $exit = self::Ok;

        foreach ($results as $result) {
            if ($result->failure === null) {
                continue;
            }

            if (! $result->blocking) {
                $exit = $exit === self::Ok ? self::NotReady : $exit;

                continue;
            }

            if ($result->failure === FailureKind::Violation) {
                return self::Violation;
            }

            $exit = self::Unavailable;
        }

        return $exit;
    }

    /**
     * The name of the exit code in the JSON document: ok, unavailable, violation or not_ready.
     */
    public function status(): string
    {
        return match ($this) {
            self::Ok => 'ok',
            self::Unavailable => FailureKind::Unavailable->value,
            self::Violation => FailureKind::Violation->value,
            self::NotReady => 'not_ready',
        };
    }

    /**
     * Whether the kernel may start with this result: at Ok and NotReady, which only readiness
     * checks cause.
     */
    public function allowsStart(): bool
    {
        return $this === self::Ok || $this === self::NotReady;
    }
}
