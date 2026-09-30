<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedRequest;
use Cbox\Cms\Core\Seeding\Domain\SeedProfiles;
use InvalidArgumentException;

/**
 * Reads the options of cms:seed-scale into a SeedRequest: --profile, a profile of the kernel,
 * --seed, a whole number of 0 or more, and --entries, a whole number of 1 or more; underscores may
 * group digits, as in 1_000_000.
 */
#[Internal]
final readonly class SeedScaleOptions
{
    /**
     * @throws InvalidArgumentException for an unknown profile or an option that is not a whole number in range
     */
    public static function parse(mixed $profile, mixed $seed, mixed $entries): SeedRequest
    {
        if (! is_string($profile)) {
            throw new InvalidArgumentException('Give --profile as the name of a seed profile: '.implode(', ', SeedProfiles::names()).'.');
        }

        return new SeedRequest(SeedProfiles::named($profile), self::number('seed', $seed), self::number('entries', $entries));
    }

    private static function number(string $option, mixed $value): int
    {
        $digits = is_string($value) ? str_replace('_', '', $value) : null;

        if ($digits === null || preg_match('/\A(?:0|[1-9][0-9]{0,17})\z/', $digits) !== 1) {
            throw new InvalidArgumentException(sprintf('Give --%s as a whole number of 0 or more, such as 1000000 or 1_000_000.', $option));
        }

        return (int) $digits;
    }
}
