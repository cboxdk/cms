<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Closure;
use JsonSerializable;
use LogicException;
use SensitiveParameter;
use Stringable;

/**
 * A password someone typed, held only as long as the call that checks or hashes it (PRD 5.16). It
 * is sensitive (GUARDRAILS 6): reveal() is the only way to read it, and nothing else shows it.
 *
 * The string form, json_encode(), var_dump(), print_r() and var_export() show REDACTED or nothing,
 * serialize() refuses, and a stack trace shows only the object, because the value is held in a
 * closure, not in a property, and the constructor's parameter is #[SensitiveParameter]. Messages of
 * InvalidIdentity never repeat it. A password is not empty; its length rules belong to the policy
 * that registers it, not to the value.
 */
#[Experimental]
final readonly class Password implements JsonSerializable, Stringable
{
    /** What every form of a password but reveal() shows. */
    public const string REDACTED = '[redacted password]';

    /** @var Closure(): string */
    private Closure $value;

    /**
     * @throws InvalidIdentity when the password is empty
     */
    public function __construct(#[SensitiveParameter] string $value)
    {
        if ($value === '') {
            throw InvalidIdentity::password();
        }

        $this->value = static fn (): string => $value;
    }

    /**
     * The password itself, for the code that hashes it or checks it. Never log, store or send it.
     */
    public function reveal(): string
    {
        return ($this->value)();
    }

    public function __toString(): string
    {
        return self::REDACTED;
    }

    public function jsonSerialize(): string
    {
        return self::REDACTED;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['password' => self::REDACTED];
    }

    /**
     * @return array<string, never>
     *
     * @throws LogicException always: a password is never serialised
     */
    public function __serialize(): array
    {
        throw new LogicException('A password is never serialised.');
    }

    /**
     * @param  array<string, string>  $data
     *
     * @throws LogicException always: a password is never serialised
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A password is never serialised.');
    }
}
