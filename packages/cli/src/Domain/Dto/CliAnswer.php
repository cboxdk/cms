<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ExitCode;

/**
 * What cms:run answers a call with (GUARDRAILS 2.1): the exit code, from the error catalog, the
 * lines it prints on standard output, and the lines it prints on standard error.
 */
#[Internal]
final readonly class CliAnswer
{
    /**
     * @param  list<string>  $output
     * @param  list<string>  $errors
     */
    public function __construct(
        public ExitCode $exit,
        public array $output,
        public array $errors = [],
    ) {}
}
