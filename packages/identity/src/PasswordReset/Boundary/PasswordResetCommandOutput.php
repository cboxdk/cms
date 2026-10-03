<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\PasswordReset\Boundary;

use Cbox\Cms\Cli\Domain\Dto\CliAnswer;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Core\CoreServiceProvider;
use Cbox\Cms\Core\Process\Boundary\ProcessWorkload;
use Cbox\Cms\Core\Process\Domain\Workload;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\IssuedResetLink;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\ResetLinkOutcome;
use Cbox\Cms\Identity\PasswordReset\Domain\InvalidPasswordReset;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * What cms:staff:reset-link and cms:identity:prune answer (GUARDRAILS 2.1), with exit codes from the
 * error catalog. Both run only in the maintenance process, the console process with the owner
 * connection (maintenance_process_required, 78, anywhere else).
 *
 * cms:staff:reset-link answers 0 with the link alone on the first line of standard output, so a
 * script can read it, and when it expires on the second; 64 for a text that is not a login;
 * the exit code of local_account_missing (67) for a login without a local account and of
 * actor_not_active (77) for an account whose actor is not active, with `[<code>] <cause>` on
 * standard error; and 78 for invalid settings. cms:identity:prune answers 0 with how many tokens it
 * removed. No answer holds the email address.
 */
#[Internal]
final readonly class PasswordResetCommandOutput
{
    private function __construct() {}

    /**
     * A refusal of the process itself, before anything is read.
     */
    public static function refusedProcess(Application $app, Repository $config, string $command): ?CliAnswer
    {
        $workload = ProcessWorkload::of($app);

        if ($workload === Workload::Console && CoreServiceProvider::ownerConnectionConfigured($config)) {
            return null;
        }

        return self::refusal(ErrorCode::MaintenanceProcessRequired, sprintf(
            '%s runs only in the maintenance process, a console process with the owner connection, not %s.',
            $command,
            $workload === Workload::Console ? 'a console process without the owner connection' : $workload->described(),
        ));
    }

    public static function usage(string $message): CliAnswer
    {
        return new CliAnswer(ExitCode::Usage, [], [$message]);
    }

    public static function invalidSettings(InvalidPasswordReset $invalid): CliAnswer
    {
        return new CliAnswer(ExitCode::Config, [], [$invalid->getMessage()]);
    }

    public static function link(ResetLinkOutcome $outcome): CliAnswer
    {
        $link = $outcome->link;

        if ($link instanceof IssuedResetLink) {
            return new CliAnswer(ExitCode::Ok, [
                $link->link(),
                sprintf('The link expires at %s and sets the password once.', $link->expiresAt->format('Y-m-d\TH:i:s\Z')),
            ]);
        }

        return match ($outcome->refusal) {
            ErrorCode::ActorNotActive => self::refusal(ErrorCode::ActorNotActive, 'The actor of the account is not active, so its password cannot be reset.'),
            ErrorCode::LoginClassNotAllowed,
            ErrorCode::LoginConnectionNotAllowed,
            ErrorCode::LoginMethodNotAllowed,
            ErrorCode::LoginLocalDisabled,
            ErrorCode::LoginAuthoritativeLink => self::refusal($outcome->refusal, 'The login policy does not let the actor of the account log in by password reset, so its password cannot be reset.'),
            default => self::refusal(ErrorCode::LocalAccountMissing, 'No local account has this email as its login.'),
        };
    }

    public static function pruned(int $removed): CliAnswer
    {
        return new CliAnswer(ExitCode::Ok, [sprintf('Removed %d password reset %s used or expired more than 24 hours ago.', $removed, $removed === 1 ? 'token' : 'tokens')]);
    }

    private static function refusal(ErrorCode $code, string $cause): CliAnswer
    {
        return new CliAnswer($code->entry()->exit, [], [sprintf('[%s] %s', $code->value, $cause)]);
    }
}
