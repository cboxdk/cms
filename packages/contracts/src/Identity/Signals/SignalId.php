<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;

/**
 * The id of a signed signal (PRD 5.16), its jti claim: 1 to MAX_LENGTH visible ASCII characters,
 * compared exactly. It is unique only within its issuer, so a receiver remembers the issuer and the
 * jti together; the same jti of another issuer is another signal.
 */
#[Experimental]
final readonly class SignalId
{
    public const int MAX_LENGTH = 255;

    private const string PATTERN = '/\A[\x21-\x7E]{1,255}\z/';

    /**
     * @throws InvalidIdentity when the value is not a signal id
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidIdentity::signalValue('a signal id', '1 to 255 visible ASCII characters');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
