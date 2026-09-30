<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Results\FieldPath;
use RuntimeException;
use Throwable;

/**
 * A cms:* command of the cli refused a call before the kernel ran it (GUARDRAILS 2.1): for cms:run
 * nothing was committed and no idempotency key was claimed, and for the inspecting commands, such as
 * cms:explain, nothing was read. Either the call carries a code of the error catalog, with the path
 * of the value it names, such as an envelope option the envelope's codec refused
 * (`envelope.wait_level`) or a registry cache that cannot be read, and the command exits with the
 * catalog's exit code for it; or it is a usage error or a broken installation that has no code of
 * its own, and the command exits with the catalog's exit code given: 64 for arguments that name no
 * command the CLI exposes, 78 for an invalid setting, 70 for an exposed command without a codec.
 */
#[Internal]
final class CliCallRefused extends RuntimeException
{
    private function __construct(
        public readonly ?ErrorCode $errorCode,
        public readonly ExitCode $exit,
        public readonly ?FieldPath $path,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * A refusal with a code of the error catalog, exiting with the catalog's exit code for it.
     */
    public static function catalog(ErrorCode $code, ?FieldPath $path, string $message, ?Throwable $previous = null): self
    {
        return new self($code, $code->entry()->exit, $path, $message, $previous);
    }

    /**
     * Arguments that do not name a command the CLI exposes: exit 64, EX_USAGE.
     */
    public static function usage(string $message, ?Throwable $previous = null): self
    {
        return new self(null, ExitCode::Usage, null, $message, $previous);
    }

    /**
     * An invalid setting of the CLI surface: exit 78, EX_CONFIG.
     */
    public static function config(string $message, ?Throwable $previous = null): self
    {
        return new self(null, ExitCode::Config, null, $message, $previous);
    }

    /**
     * A command the registry exposes on the CLI that no codec reads, a broken installation: exit
     * 70, EX_SOFTWARE.
     */
    public static function software(string $message, ?Throwable $previous = null): self
    {
        return new self(null, ExitCode::Software, null, $message, $previous);
    }
}
