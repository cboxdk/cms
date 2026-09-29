<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;

/**
 * The decision of phase 2 (PRD 6.2): allowed, or refused with the reason in plain language, which
 * the kernel answers as unauthorized.
 */
#[Internal]
final readonly class Authorization
{
    private function __construct(public ?string $reason) {}

    public static function allow(): self
    {
        return new self(null);
    }

    public static function refuse(string $reason): self
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A refusal gives its reason in plain language.');
        }

        return new self($reason);
    }

    public function allowed(): bool
    {
        return $this->reason === null;
    }
}
