<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The name an actor's profile shows for it (PRD 5.16), such as a person's full name or what a
 * service does. It is personal data, classified personal (PRD 12.2): it lives in the actor's
 * profile, never in an event or the audit, and no message repeats it. It is 1 to MAX_LENGTH
 * characters with no control character, and starts and ends with a character that is not white
 * space, as PATTERN says.
 */
#[Experimental]
final readonly class DisplayName
{
    /** The longest display name, in characters. */
    public const int MAX_LENGTH = 200;

    /** The form of a display name, as the JSON Schema of actor.register states it. */
    public const string PATTERN = '^[^\s\x00-\x1F\x7F]([^\x00-\x1F\x7F]*[^\s\x00-\x1F\x7F])?$';

    public function __construct(public string $value)
    {
        if (
            preg_match('/'.self::PATTERN.'/u', $value) !== 1
            || mb_strlen($value, 'UTF-8') > self::MAX_LENGTH
        ) {
            throw InvalidIdentity::displayName();
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
