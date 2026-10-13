<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Cli\Domain\Dto\CliAnswer;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Core\Maintenance\Domain\CredentialIssueRefused;
use Cbox\Cms\Core\Maintenance\Domain\Dto\IssuedToken;
use DateTimeInterface;

/**
 * What cms:credential:issue answers (GUARDRAILS 2.1), with exit codes from the error catalog: 0
 * with the token on standard output, alone on its line so a script can read it and nothing else
 * ever prints it, and on standard error what was issued, to whom and until when, without the
 * token; for a refusal, the exit code of its catalog code and `<code>: <cause>` on standard error
 * through RefusalOutput.
 */
#[Internal]
final readonly class CredentialIssueOutput
{
    public function __construct(private RefusalOutput $refusals) {}

    public static function issued(IssuedToken $issued): CliAnswer
    {
        return new CliAnswer(ExitCode::Ok, [$issued->token->reveal()], [sprintf(
            'Issued a service credential to the service actor %s with the ceiling %s, expiring at %s. The token is shown once and stored only as its hash; keep it now.',
            $issued->credential->actor->toString(),
            $issued->credential->ceiling->value,
            $issued->credential->expiresAt->format(DateTimeInterface::RFC3339_EXTENDED),
        )]);
    }

    public function refused(CredentialIssueRefused $refusal): CliAnswer
    {
        return $this->refusals->errors(false, $refusal->error);
    }
}
