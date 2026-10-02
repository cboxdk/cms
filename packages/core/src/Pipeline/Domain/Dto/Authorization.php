<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use InvalidArgumentException;

/**
 * The decision of phase 2 (PRD 6.2): allowed, or refused with the reason in plain language and the
 * catalog code the kernel answers with: unauthorized, unless the refusal names another, such as
 * grant_escalation_refused or step_up_required for a grant the issuing actor may not give.
 */
#[Internal]
final readonly class Authorization
{
    private function __construct(
        public ?string $reason,
        public ErrorCode $code = ErrorCode::Unauthorized,
    ) {}

    public static function allow(): self
    {
        return new self(null);
    }

    public static function refuse(string $reason, ErrorCode $code = ErrorCode::Unauthorized): self
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A refusal gives its reason in plain language.');
        }

        return new self($reason, $code);
    }

    public function allowed(): bool
    {
        return $this->reason === null;
    }
}
