<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;

/**
 * One reason cms:build refused to write the registry: the code, and a message that names the
 * classes involved and says how to fix it.
 */
#[Experimental]
final readonly class BuildProblem
{
    public function __construct(
        public BuildErrorCode $code,
        public string $message,
    ) {}

    public function describe(): string
    {
        return sprintf('[%s] %s', $this->code->value, $this->message);
    }
}
