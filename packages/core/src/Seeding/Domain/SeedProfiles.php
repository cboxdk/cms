<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The kernel's seed profiles (GUARDRAILS 4.3): `small` for fixtures and tests, and `scale` for the
 * scale data set that the NFR targets of PRD 23 run against. Both have the same skew; they differ
 * in the size of a chunk, which keeps a chunk's command transaction well under two seconds (PRD
 * 7.4) at the scale profile's larger data set and small enough for a test to reach several chunks.
 */
#[Internal]
final readonly class SeedProfiles
{
    public const string SMALL = 'small';

    public const string SCALE = 'scale';

    /**
     * The profile with the name.
     *
     * @throws InvalidSeed for a name the kernel has no profile of
     */
    public static function named(string $name): SeedProfile
    {
        return match ($name) {
            self::SMALL => self::small(),
            self::SCALE => self::scale(),
            default => throw InvalidSeed::unknownProfile($name, self::names()),
        };
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return [self::SCALE, self::SMALL];
    }

    public static function small(): SeedProfile
    {
        return new SeedProfile(
            name: self::SMALL,
            version: 1,
            chunkSize: 100,
            typeSkew: 1.0,
            nodeSkew: 1.1,
            valueSkew: 1.0,
            releasedPercent: 90,
            filledPercent: 80,
            anchor: '2026-01-01',
            spanDays: 3650,
            dateSkew: 3.0,
        );
    }

    public static function scale(): SeedProfile
    {
        return new SeedProfile(
            name: self::SCALE,
            version: 1,
            chunkSize: 200,
            typeSkew: 1.0,
            nodeSkew: 1.1,
            valueSkew: 1.0,
            releasedPercent: 90,
            filledPercent: 80,
            anchor: '2026-01-01',
            spanDays: 3650,
            dateSkew: 3.0,
        );
    }
}
