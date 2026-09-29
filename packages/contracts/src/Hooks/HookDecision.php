<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Hooks;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What an AuthorizeHook answers (PRD 6.2 phase 2): no objection, or a denial with the reason in
 * plain language, which the kernel answers as unauthorized. There is no grant: a hook only adds
 * refusals to the kernel's decision.
 */
#[Experimental]
final readonly class HookDecision
{
    private function __construct(public ?string $reason) {}

    /**
     * The hook has no objection. The command still needs everything else the kernel checks.
     */
    public static function noObjection(): self
    {
        return new self(null);
    }

    /**
     * @throws InvalidHookResult when the reason is empty
     */
    public static function deny(string $reason): self
    {
        if (trim($reason) === '') {
            throw InvalidHookResult::emptyReason();
        }

        return new self($reason);
    }

    public function denies(): bool
    {
        return $this->reason !== null;
    }
}
