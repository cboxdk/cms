<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Scale\Domain;

use InvalidArgumentException;

/**
 * What `composer scale:check` runs (GUARDRAILS 4.3, PRD 23, MILESTONES M1): the entries to seed with
 * the seed profile and the seed, the dedicated scale database, how many sections the site has that
 * the entries are spread over, how many times the listing runs, and whether an existing scale
 * database is kept, so a run that stopped resumes its seed.
 */
final readonly class ScaleOptions
{
    public const string DATABASE = 'cms_scale';

    public const int ENTRIES = 1_000_000;

    public const int RUNS = 11;

    public const int SECTIONS = 40;

    public const int SEED = 1;

    public const string PROFILE = 'scale';

    /** The M1 exit criterion: a listing under 20 ms in the database at a million entries. */
    public const float BUDGET_MS = 20.0;

    /**
     * @throws InvalidArgumentException for values out of range
     */
    public function __construct(
        public int $entries = self::ENTRIES,
        public int $runs = self::RUNS,
        public string $profile = self::PROFILE,
        public int $seed = self::SEED,
        public string $database = self::DATABASE,
        public int $sections = self::SECTIONS,
        public bool $keep = false,
    ) {
        if ($entries < 1 || $runs < 1 || $runs > 1000 || $seed < 0 || $sections < 1 || $sections > 10_000) {
            throw new InvalidArgumentException('--entries is 1 or more, --runs 1 to 1000, --seed 0 or more and --sections 1 to 10000.');
        }

        if (preg_match('/\A[a-z][a-z0-9_]{0,62}\z/', $database) !== 1) {
            throw new InvalidArgumentException(sprintf('--database names a Postgres database of lower-case letters, digits and underscores, got "%s".', $database));
        }
    }
}
