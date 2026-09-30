<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Invariants;

use Cbox\Cms\Tooling\Docs\Domain\GateSuites;

/**
 * Holds an invariant coverage map to the tests of a checkout (PRD 6.5, GUARDRAILS 9 and 11).
 *
 * problems() reports each invariant of the list that the map has no entry for or no test in, each
 * entry for an invariant outside PRD 6.5 or outside the list, an invariant mapped twice, and each
 * covering test whose file does not exist or is in no suite of gate 5, whose file declares no test
 * of its name, that its source skips, that names no issuer or an issuer twice, or that an entry
 * names twice.
 */
final readonly class InvariantCoverageAudit
{
    /** The invariants of PRD 6.5 are numbered from 1 to this. */
    public const int LAST_INVARIANT = 38;

    /**
     * @param  list<InvariantCoverage>  $map
     * @param  list<int>  $invariants  the invariants the map must cover
     * @return list<string>
     */
    public static function problems(array $map, array $invariants, string $root, GateSuites $suites): array
    {
        $problems = [];
        $mapped = [];

        foreach ($map as $entry) {
            if ($entry->invariant < 1 || $entry->invariant > self::LAST_INVARIANT) {
                $problems[] = "invariant {$entry->invariant}: PRD 6.5 has no invariant with this number";
            } elseif (! in_array($entry->invariant, $invariants, true)) {
                $problems[] = "invariant {$entry->invariant}: the map covers it, but it is not in the list of invariants to cover";
            }

            if (isset($mapped[$entry->invariant])) {
                $problems[] = "invariant {$entry->invariant}: the map has more than one entry for it";
            }

            $mapped[$entry->invariant] = true;
        }

        foreach ($invariants as $invariant) {
            if (! isset($mapped[$invariant])) {
                $problems[] = "invariant {$invariant}: the map has no entry for it, so no test covers it";
            }
        }

        $files = [];

        foreach ($map as $entry) {
            if ($entry->tests === []) {
                $problems[] = "invariant {$entry->invariant}: no test covers it";
            }

            $seen = [];

            foreach ($entry->tests as $test) {
                $where = "invariant {$entry->invariant}: {$test->id}";

                if (isset($seen[$test->id])) {
                    $problems[] = "{$where}: named twice for the invariant";

                    continue;
                }

                $seen[$test->id] = true;
                array_push($problems, ...self::issuerProblems($where, $test));

                $file = $root.'/'.$test->path;

                if (! is_file($file)) {
                    $problems[] = "{$where}: the file {$test->path} does not exist";

                    continue;
                }

                if (! $suites->includes($test->path)) {
                    $problems[] = "{$where}: {$test->path} is in no suite of gate 5 ({$suites->names()}), so the test never runs";

                    continue;
                }

                $read = $files[$test->path] ??= TestFileReader::read((string) file_get_contents($file));

                if (! $read->declares($test->name)) {
                    $problems[] = "{$where}: {$test->path} declares no test named '{$test->name}'";
                } elseif ($read->skips($test->name)) {
                    $problems[] = "{$where}: the test is skipped, or its file may skip it";
                }
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private static function issuerProblems(string $where, TestReference $test): array
    {
        if ($test->issuers === []) {
            return ["{$where}: names no issuer it tries the invariant through"];
        }

        $values = array_map(static fn (Issuer $issuer): string => $issuer->value, $test->issuers);
        $twice = array_keys(array_filter(array_count_values($values), static fn (int $count): bool => $count > 1));

        return array_map(static fn (string $issuer): string => "{$where}: names the issuer {$issuer} twice", $twice);
    }
}
