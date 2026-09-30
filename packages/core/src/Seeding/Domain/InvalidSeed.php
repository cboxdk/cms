<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;

/**
 * A seed request or profile the seeder cannot take: an unknown profile, a profile out of its
 * bounds, a negative seed, or no entries to seed.
 */
#[Internal]
final class InvalidSeed extends InvalidArgumentException
{
    /**
     * @param  list<string>  $known
     */
    public static function unknownProfile(string $name, array $known): self
    {
        return new self(sprintf('The kernel has no seed profile "%s"; it has %s.', $name, implode(', ', $known)));
    }

    public static function profile(string $name, int $version): self
    {
        return new self(sprintf('The seed profile "%s" version %d is out of bounds: a name of a lower-case letter and up to 31 lower-case letters, digits or underscores, a version of 1 or more, 1 to 1000 entries a chunk, skews of 0 or more, percentages of 0 to 100, an anchor day as Y-m-d, a span of 1 day or more and a date skew of 1 or more.', $name, $version));
    }

    public static function seed(int $seed): self
    {
        return new self(sprintf('A seed is a whole number of 0 or more, got %d.', $seed));
    }

    public static function entries(int $entries): self
    {
        return new self(sprintf('A seed run seeds 1 to %d entries, got %d.', SeedRequestLimits::MAX_ENTRIES, $entries));
    }

    public static function chunk(int $chunk, int $chunks): self
    {
        return new self(sprintf('The run has the chunks 0 to %d, not %d.', $chunks - 1, $chunk));
    }
}
