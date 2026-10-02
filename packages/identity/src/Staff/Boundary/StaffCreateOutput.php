<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Staff\Boundary;

use Cbox\Cms\Cli\Domain\Dto\CliAnswer;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Identity\BreachedPasswordsUnavailable;
use Cbox\Cms\Identity\Staff\Domain\Dto\RegisteredStaff;
use Cbox\Cms\Identity\Staff\Domain\StaffRegistrationRefused;

/**
 * What cms:staff:create answers (GUARDRAILS 2.1), with exit codes from the error catalog: 0 with the
 * actor's id on standard output, alone on its line so a script can read it; for a refusal, the
 * exit code of its catalog code and `[<code>] <cause>` on standard error. No answer holds the email
 * address or the password.
 */
#[Internal]
final readonly class StaffCreateOutput
{
    public static function registered(RegisteredStaff $staff): CliAnswer
    {
        return new CliAnswer(ExitCode::Ok, [$staff->actor->toString()]);
    }

    public static function refused(StaffRegistrationRefused|BreachedPasswordsUnavailable $refusal): CliAnswer
    {
        $code = $refusal instanceof StaffRegistrationRefused ? $refusal->reason : ErrorCode::BreachedPasswordsUnavailable;

        return new CliAnswer($code->entry()->exit, [], [sprintf('[%s] %s', $code->value, $refusal->getMessage())]);
    }
}
