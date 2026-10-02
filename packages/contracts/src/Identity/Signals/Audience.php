<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;

/**
 * An audience of a signed token (PRD 5.16), the aud claim: the client id the CMS has at the
 * identity provider for a logout token, or the audience of the stream for a security event token.
 * 1 to MAX_LENGTH visible ASCII characters, compared exactly. A receiver takes a token only when its
 * audience lists the audience the connection is pinned to.
 */
#[Experimental]
final readonly class Audience
{
    public const int MAX_LENGTH = 255;

    private const string PATTERN = '/\A[\x21-\x7E]{1,255}\z/';

    /**
     * @throws InvalidIdentity when the value is not an audience
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidIdentity::signalValue('an audience', '1 to 255 visible ASCII characters');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Checks a token's audience list: at least one audience, each once.
     *
     * @param  list<self>  $audience
     *
     * @throws InvalidIdentity when the list is empty or names one twice
     */
    public static function checkList(array $audience): void
    {
        if ($audience === []) {
            throw InvalidIdentity::signalValue('audience of a token', 'at least one audience');
        }

        $seen = [];

        foreach ($audience as $one) {
            if (isset($seen[$one->value])) {
                throw InvalidIdentity::signalValue('audience of a token', 'a list that names each audience once');
            }

            $seen[$one->value] = true;
        }
    }

    /**
     * Whether the list names this audience.
     *
     * @param  list<self>  $audience
     */
    public function isListedIn(array $audience): bool
    {
        return array_any($audience, fn (Audience $one): bool => $one->equals($this));
    }
}
