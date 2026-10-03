<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Login\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use SensitiveParameter;

/**
 * The login identifier a form's email field took (PRD 5.16), as a Boundary read it, one of three:
 *
 * - of(): a LoginIdentifier, as LoginIdentifier::typed() reads what was typed;
 * - missing(): the field was left empty, or held only white space, which a form refuses with
 *   validation_required;
 * - unreadable(): something was typed that is no login identifier, such as text with white space
 *   inside or a control character; it names no account, so a login of it is refused as any
 *   unknown login is, and no link is mailed for it.
 *
 * var_dump() and a stack trace never show the identifier.
 */
#[Internal]
final readonly class TypedLogin
{
    private function __construct(
        public ?LoginIdentifier $identifier,
        public bool $given,
    ) {}

    public static function of(#[SensitiveParameter] LoginIdentifier $identifier): self
    {
        return new self($identifier, true);
    }

    public static function missing(): self
    {
        return new self(null, false);
    }

    public static function unreadable(): self
    {
        return new self(null, true);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['identifier' => match (true) {
            $this->identifier instanceof LoginIdentifier => '[personal]',
            $this->given => '[unreadable]',
            default => '[missing]',
        }];
    }
}
