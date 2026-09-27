<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Doctor;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * One check of `cms:doctor`: part of the installation or of the runtime contract (PRD 3.3, 4.2).
 *
 * run() looks and never changes anything, so running it twice in the same state gives the same
 * result. It returns Pass or Fail and does not throw: a dependency that cannot be reached, or a
 * setting that is wrong, is a Fail with its kind, an error code, the cause and the fix. The id and
 * blocking of the result are the check's own.
 *
 * blocking() says whether the kernel refuses to start while the check fails. A check that is not
 * blocking only affects readiness, so a cold start never blocks itself: for example the partition
 * runway, which the scheduler of the started application extends. When only such checks fail,
 * cms:doctor exits 79 (DoctorExitCode::NotReady).
 *
 * requires() lists checks that must pass first. The doctor runs the checks in their order and
 * skips a check whose requirement did not pass, so a check can rely on what an earlier one showed,
 * such as a reachable Postgres. A blocking check requires only blocking checks, so a readiness
 * failure never skips a blocking check.
 *
 * The testkit has a fake, FakeDoctorCheck, and the shared suite DoctorCheckContract.
 */
#[Experimental]
interface DoctorCheck
{
    public function id(): CheckId;

    public function blocking(): bool;

    /**
     * @return list<CheckId>
     */
    public function requires(): array;

    public function run(): CheckResult;
}
