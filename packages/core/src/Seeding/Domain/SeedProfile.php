<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A versioned profile of a seeded data set (GUARDRAILS 4.3, PRD 23 "Skalaprofil"): how many entries
 * one chunk holds, and how skewed the data is. The skew is in the type mix (a Zipf distribution over
 * the seedable types of the catalog, the first by name the most frequent), in the entries per node
 * (a Zipf distribution over the nodes the seeding actor reaches, the first by id the fullest) and in
 * the field values: dates and date-times crowd towards the profile's anchor, numbers towards their
 * low end, choices towards their first options, optional fields are left out now and then, and
 * text varies in length. Commit times are not skewed: every chunk commits at the Clock's time.
 *
 * The profile and its version name the data set together with the seed: the same profile, version,
 * seed and entry index give the same entry in every run. A change to any number below is a new
 * version, so a data set is never mixed from two.
 */
#[Internal]
final readonly class SeedProfile
{
    public const string NAME_PATTERN = '/\A[a-z][a-z0-9_]{0,31}\z/';

    public DateTimeImmutable $anchor;

    /**
     * @param  string  $name  the profile's name, such as "small"
     * @param  int  $version  its version, 1 or more
     * @param  int  $chunkSize  the entries of one chunk, one changeset each, 1 to 1000
     * @param  float  $typeSkew  the Zipf exponent of the type mix, 0 for an even mix
     * @param  float  $nodeSkew  the Zipf exponent of the entries per node, 0 for an even spread
     * @param  float  $valueSkew  the Zipf exponent of choices, 0 for an even pick
     * @param  int  $releasedPercent  how many of the entries of a releasable type are released, 0 to 100
     * @param  int  $filledPercent  how many optional fields hold a value, 0 to 100
     * @param  string  $anchor  the newest day of the date fields, Y-m-d
     * @param  int  $spanDays  how many days before the anchor the oldest date lies, 1 or more
     * @param  float  $dateSkew  how strongly dates crowd towards the anchor: the exponent on a uniform draw, 1 for none
     */
    public function __construct(
        public string $name,
        public int $version,
        public int $chunkSize,
        public float $typeSkew,
        public float $nodeSkew,
        public float $valueSkew,
        public int $releasedPercent,
        public int $filledPercent,
        string $anchor,
        public int $spanDays,
        public float $dateSkew,
    ) {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $anchor, new DateTimeZone('UTC'));

        if (preg_match(self::NAME_PATTERN, $name) !== 1
            || $version < 1
            || $chunkSize < 1 || $chunkSize > 1000
            || $typeSkew < 0 || $nodeSkew < 0 || $valueSkew < 0
            || $releasedPercent < 0 || $releasedPercent > 100
            || $filledPercent < 0 || $filledPercent > 100
            || ! $day instanceof DateTimeImmutable || $day->format('Y-m-d') !== $anchor
            || $spanDays < 1
            || $dateSkew < 1) {
            throw InvalidSeed::profile($name, $version);
        }

        $this->anchor = $day;
    }

    /**
     * The profile's name and version, as a unit of work and an operation key name it: "small@1".
     */
    public function label(): string
    {
        return $this->name.'@'.$this->version;
    }
}
