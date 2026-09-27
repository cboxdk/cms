<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Decides for SystemClockRule whether a date string or a date format that the code spells out
 * makes PHP read the system clock.
 */
#[Internal]
final class DateStrings
{
    /**
     * The base timestamps a date string is parsed against. A string whose instant is the same
     * for all of them does not depend on the current time.
     *
     * @var list<int>
     */
    public const array BASES = [0, 1_000_000_000, 1_234_567_891];

    /**
     * The fields of a date that a createFromFormat() format must set, each with the format
     * characters that set it. A field the format leaves out is taken from the current time.
     * Fractions of a second are not: they are zero when the format has no u or v.
     *
     * @var array<string, string>
     */
    public const array FORMAT_FIELDS = [
        'year' => 'YyXx',
        'month' => 'mnMFz',
        'day' => 'djz',
        'hour' => 'HGhg',
        'minute' => 'i',
        'second' => 's',
    ];

    /**
     * True when new DateTimeImmutable($value) and the parsers like it read the current time:
     * an empty string, "now", "today", "+1 day", "10:00", "first monday of january" and every
     * other string whose instant depends on when it is parsed. "2026-01-01", "@1767225600"
     * and "2026-01-01T10:00:00+02:00" do not. A string PHP cannot parse throws; it reads no
     * clock.
     */
    public static function dependsOnNow(string $value): bool
    {
        if (trim($value) === '') {
            return true;
        }

        $instants = [];

        foreach (self::BASES as $base) {
            $instants[] = strtotime($value, $base);
        }

        return $instants[0] !== false && count(array_unique($instants)) > 1;
    }

    /**
     * True when createFromFormat() with this format takes a field from the current time: when
     * the format has neither ! nor |, which reset the fields it leaves out, nor U, the unix
     * timestamp, and leaves out the year, month, day, hour, minute or second. "Y-m-d" takes
     * the time of day from the clock; "!Y-m-d", "Y-m-d|" and "Y-m-d H:i:s" do not.
     */
    public static function formatDependsOnNow(string $format): bool
    {
        $set = [];

        for ($index = 0, $length = strlen($format); $index < $length; $index++) {
            $character = $format[$index];

            if ($character === '\\') {
                $index++;

                continue;
            }

            if (in_array($character, ['!', '|', 'U'], true)) {
                return false;
            }

            foreach (self::FORMAT_FIELDS as $field => $characters) {
                if (str_contains($characters, $character)) {
                    $set[$field] = true;
                }
            }
        }

        return count($set) < count(self::FORMAT_FIELDS);
    }
}
