<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use InvalidArgumentException;

/**
 * The decision of phase 2 (PRD 6.2): allowed, or refused with the reason in plain language and the
 * catalog code the kernel answers with: unauthorized, unless the refusal names another, such as
 * grant_escalation_refused or step_up_required for a grant the issuing actor may not give.
 *
 * An allowed decision may name what it relied on beyond the action's aggregates, each with the
 * version the authorizer read, such as the issuing actor's set of grants the escalation guard
 * decided from (invariant 31). The kernel adds them to the call's reads, so the commit locks them
 * and a change of one meanwhile is version_conflict, not a decision on stale grounds.
 */
#[Internal]
final readonly class Authorization
{
    private function __construct(
        public ?string $reason,
        public ErrorCode $code = ErrorCode::Unauthorized,
        public ReadVersions $reads = new ReadVersions,
    ) {}

    /**
     * Allowed, relying on the reads given besides the action's aggregates.
     */
    public static function allow(ReadVersion ...$reads): self
    {
        return new self(null, reads: new ReadVersions(...$reads));
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

    /**
     * This decision, relying on the reads given too when it allows; a refusal stays as it is.
     */
    public function relyingOn(ReadVersion ...$reads): self
    {
        if (! $this->allowed() || $reads === []) {
            return $this;
        }

        return self::allow(...$this->reads->reads, ...$reads);
    }
}
