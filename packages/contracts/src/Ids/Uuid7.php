<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Ids;

use Cbox\Cms\Contracts\Attributes\Experimental;
use DateTimeInterface;
use Random\Randomizer;

/**
 * A UUID version 7 (RFC 9562, section 5.7). Aggregates are identified by UUIDv7 (PRD 5.3), and the
 * typed ids such as ChangesetId are built on this value.
 *
 * The value is the canonical string: 36 characters, lowercase hex with hyphens. The first 48 bits
 * are the unix time in milliseconds, so the string sorts as time (PRD 4.1: tables are partitioned
 * on ranges of UUIDv7).
 *
 * The 74 bits after the version and variant bits are laid out as RFC 9562, section 6.2, method 1:
 * a 42-bit counter (the 12 bits of rand_a and the first 30 bits of rand_b) followed by 32 random
 * bits. generate() starts the counter at a random value with its top bit clear in every new
 * millisecond and increments it within a millisecond, so ids from one generator sort in the order
 * they were made.
 */
#[Experimental]
final readonly class Uuid7
{
    /** The largest unix time in milliseconds that fits in 48 bits, in the year 10889. */
    public const int MAX_UNIX_MILLISECONDS = 0xFFFF_FFFF_FFFF;

    private const int COUNTER_BITS = 42;

    private const int MAX_COUNTER = (1 << self::COUNTER_BITS) - 1;

    /** A new millisecond starts the counter below this value, so it has room to count (RFC 9562, 6.2). */
    private const int FRESH_COUNTER_LIMIT = 1 << (self::COUNTER_BITS - 1);

    private const int RANDOM_TAIL_BITS = 32;

    private const int MAX_RANDOM_TAIL = (1 << self::RANDOM_TAIL_BITS) - 1;

    private const int MAX_RAND_A = 0xFFF;

    private const int MAX_RAND_B = (1 << 62) - 1;

    private const string PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    /** The canonical form: lowercase hex, 8-4-4-4-12. */
    public string $value;

    private int $unixMilliseconds;

    private int $counter;

    /**
     * Parses a UUIDv7 in the canonical 8-4-4-4-12 form. Upper case hex is accepted and stored in
     * lower case. Anything else, including another UUID version, throws InvalidUuid7.
     */
    public function __construct(string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidUuid7::malformed($value);
        }

        $value = strtolower($value);

        if ($value[14] !== '7') {
            throw InvalidUuid7::wrongVersion($value);
        }

        $variant = intval($value[19], 16);

        if (($variant & 0b1100) !== 0b1000) {
            throw InvalidUuid7::wrongVariant($value);
        }

        $hex = str_replace('-', '', $value);
        $randA = intval(substr($hex, 13, 3), 16);
        $counterLow = intval(substr($hex, 17, 7), 16) | (($variant & 0b11) << 28);

        $this->value = $value;
        $this->unixMilliseconds = intval(substr($hex, 0, 12), 16);
        $this->counter = ($randA << 30) | $counterLow;
    }

    /**
     * The next id at the given unix time, strictly after $after when one is given.
     *
     * When $after is in an earlier millisecond, the id uses the given time and a fresh counter.
     * When $after is in the same millisecond or a later one, because the clock repeated a
     * millisecond or stepped back, the id keeps the millisecond of $after and increments its
     * counter. When the counter is full, the id moves to the next millisecond.
     */
    public static function generate(int $unixMilliseconds, Randomizer $random, ?self $after = null): self
    {
        self::assertUnixMilliseconds($unixMilliseconds);

        if (! $after instanceof Uuid7 || $unixMilliseconds > $after->unixMilliseconds) {
            $milliseconds = $unixMilliseconds;
            $counter = $random->getInt(0, self::FRESH_COUNTER_LIMIT - 1);
        } elseif ($after->counter < self::MAX_COUNTER) {
            $milliseconds = $after->unixMilliseconds;
            $counter = $after->counter + 1;
        } else {
            $milliseconds = $after->unixMilliseconds + 1;
            $counter = $random->getInt(0, self::FRESH_COUNTER_LIMIT - 1);

            if ($milliseconds > self::MAX_UNIX_MILLISECONDS) {
                throw InvalidUuid7::exhausted($after);
            }
        }

        $tail = $random->getInt(0, self::MAX_RANDOM_TAIL);

        return self::fromFields(
            $milliseconds,
            $counter >> 30,
            (($counter & ((1 << 30) - 1)) << self::RANDOM_TAIL_BITS) | $tail,
        );
    }

    /**
     * The lowest UUIDv7 in the given millisecond: every other bit after the time is zero. Use it
     * as the inclusive lower bound of a range of ids, such as a partition.
     */
    public static function lowestAt(int $unixMilliseconds): self
    {
        self::assertUnixMilliseconds($unixMilliseconds);

        return self::fromFields($unixMilliseconds, 0, 0);
    }

    /**
     * The highest UUIDv7 in the given millisecond: every bit after the time is one, except the
     * version and variant bits.
     */
    public static function highestAt(int $unixMilliseconds): self
    {
        self::assertUnixMilliseconds($unixMilliseconds);

        return self::fromFields($unixMilliseconds, self::MAX_RAND_A, self::MAX_RAND_B);
    }

    /**
     * The unix time of an instant in whole milliseconds, rounded down. Instants before 1970 are
     * negative.
     */
    public static function unixMillisecondsOf(DateTimeInterface $time): int
    {
        return $time->getTimestamp() * 1000 + intdiv((int) $time->format('u'), 1000);
    }

    /**
     * The unix time in milliseconds stored in the first 48 bits.
     */
    public function unixMilliseconds(): int
    {
        return $this->unixMilliseconds;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Negative when this id sorts before the other, zero when equal, positive when after. The
     * order is the order of the canonical strings, which is the order of the 128 bits.
     */
    public function compareTo(self $other): int
    {
        return strcmp($this->value, $other->value);
    }

    private static function fromFields(int $unixMilliseconds, int $randA, int $randB): self
    {
        $hex = sprintf(
            '%012x7%03x%x%015x',
            $unixMilliseconds,
            $randA,
            0b1000 | ($randB >> 60),
            $randB & 0x0FFF_FFFF_FFFF_FFFF,
        );

        return new self(sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ));
    }

    private static function assertUnixMilliseconds(int $unixMilliseconds): void
    {
        if ($unixMilliseconds < 0 || $unixMilliseconds > self::MAX_UNIX_MILLISECONDS) {
            throw InvalidUuid7::timeOutOfRange($unixMilliseconds);
        }
    }
}
