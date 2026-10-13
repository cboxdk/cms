<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Cli\Domain\Dto\CliAnswer;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Core\Maintenance\Domain\Dto\RegisteredService;
use Cbox\Cms\Core\Maintenance\Domain\ServiceRegistrationRefused;

/**
 * What cms:service:create answers (GUARDRAILS 2.1), with exit codes from the error catalog: 0 with
 * the actor's id on standard output, alone on its line so a script can read it, and the two
 * changesets on standard error; for a refusal, the exit code of its catalog code, and
 * `<code>: <cause>` on standard error through RefusalOutput. No answer holds the email address.
 */
#[Internal]
final readonly class ServiceCreateOutput
{
    public function __construct(private RefusalOutput $refusals) {}

    public static function registered(RegisteredService $service): CliAnswer
    {
        return new CliAnswer(ExitCode::Ok, [$service->actor->toString()], [sprintf(
            'Registered the service actor %s in changeset %s and activated it in changeset %s. Issue its credential with cms:credential:issue %1$s.',
            $service->actor->toString(),
            $service->registered->toString(),
            $service->activated->toString(),
        )]);
    }

    public function refused(ServiceRegistrationRefused $refusal): CliAnswer
    {
        return $this->refusals->errors(false, $refusal->error);
    }
}
