<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;

/**
 * One reason cms:generate stopped: the code, and a message that says where the problem is and
 * how to fix it.
 */
#[Internal]
final readonly class GenerationProblem
{
    public function __construct(
        public GenerateErrorCode $code,
        public string $message,
    ) {}

    public function describe(): string
    {
        return sprintf('[%s] %s', $this->code->value, $this->message);
    }
}
