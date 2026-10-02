<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

use Closure;
use InvalidArgumentException;
use Pest\Mutate\Mutators\Equality\GreaterToGreaterOrEqual;
use Pest\Mutate\Mutators\Logical\InstanceOfToTrue;
use Pest\Mutate\Mutators\Logical\TrueToFalse;
use Pest\Mutate\Mutators\Number\DecrementInteger;
use Pest\Mutate\Mutators\Number\IncrementInteger;
use Pest\Mutate\Mutators\Removal\RemoveArrayItem;
use Pest\Mutate\Mutators\Removal\RemoveMethodCall;

/**
 * The equivalent mutations of mutation on changed files (Sylvester's decision of 2 October,
 * GUARDRAILS 7.3): mutations that no test can catch, because the mutated code behaves as the
 * original does, where removing the construct from the code was not simple. Where it was, the
 * construct is gone and a test holds the behaviour (TypeRules, HrtimeStopwatch and
 * RegistryAffectedProjections, M1-T66). A listed mutation is not applicable: the score of its
 * source is taken over the other mutations, and it never counts as caught. The minimum score stays.
 *
 * The list is held exact. When a step judges a source, an entry for it that names no mutation of
 * the run, or names one a test caught, fails the step as stale: the code moved, or a test catches
 * it now, so the entry goes. tests/Feature/Tooling/Mutation/EquivalentMutationsTest.php also holds
 * every entry of kernel() to a mutation Pest makes of the source as it is, in every `composer
 * check`, so an entry cannot outlive its code until the source changes again.
 */
final readonly class EquivalentMutations
{
    /**
     * @param  list<EquivalentMutation>  $entries
     */
    public function __construct(public array $entries)
    {
        $seen = [];

        foreach ($entries as $entry) {
            $key = $entry->describe();

            if (isset($seen[$key])) {
                throw new InvalidArgumentException("The equivalent mutation {$key} is listed twice.");
            }

            $seen[$key] = $entry;
        }
    }

    /**
     * The kernel's list, by source and line.
     */
    public static function kernel(): self
    {
        $pacing = 'packages/core/src/Subscriptions/Adapter/SystemPacing.php';
        $inertia = 'packages/http/src/Inertia/Boundary/InertiaRequest.php';
        $migrations = 'packages/testkit/src/Postgres/OwnerMigrations.php';

        return new self([
            new EquivalentMutation($pacing, 27, GreaterToGreaterOrEqual::class, 'sleep(0) then calls usleep(0), which returns at once, as skipping the call does.'),
            new EquivalentMutation($pacing, 27, DecrementInteger::class, '> -1 lets only 0 more through than > 0, and usleep(0) returns at once, as skipping the call does.'),
            new EquivalentMutation($pacing, 28, DecrementInteger::class, 'A wait of 999 instead of 1000 microseconds a millisecond differs by 0.1 %, far below what the scheduler lets any test measure.'),
            new EquivalentMutation($pacing, 28, IncrementInteger::class, 'A wait of 1001 instead of 1000 microseconds a millisecond differs by 0.1 %, far below what the scheduler lets any test measure.'),
            new EquivalentMutation($inertia, 41, InstanceOfToTrue::class, 'InertiaDocument::members() hands the envelope codec an object it encoded itself, so every DecodingFailed the codec throws has a path, and the branch for a missing path is never taken.'),
            new EquivalentMutation($migrations, 33, RemoveMethodCall::class, 'The partition sweep only keeps migrate:fresh within the lock table when an earlier run left thousands of partitions; in every other run it drops nothing that migrate:fresh does not drop.'),
            new EquivalentMutation($migrations, 37, TrueToFalse::class, 'migrate:fresh asks for confirmation only in production, and tests never run in production, so --force false changes nothing.'),
            new EquivalentMutation($migrations, 37, RemoveArrayItem::class, 'Leaving out --force changes nothing for the same reason: migrate:fresh asks for confirmation only in production.'),
            new EquivalentMutation($migrations, 49, TrueToFalse::class, 'Without the flag every test migrates again, which leaves the same schema; the tests only take longer.'),
        ]);
    }

    /**
     * Each entry that names no mutation Pest makes of its source as it is: the source is gone, or
     * its mutator can mutate nothing on its line. The code moved or changed, so the entry goes.
     *
     * @param  Closure(string, string): ?list<int>  $sites  the lines where a mutator, by class, can
     *                                                      mutate the source at a path, or null
     *                                                      when there is no such source
     * @return list<string>
     */
    public function unsited(Closure $sites): array
    {
        $stale = [];

        foreach ($this->entries as $entry) {
            $lines = $sites($entry->path, $entry->mutator);

            if ($lines === null) {
                $stale[] = "{$entry->describe()} names a source that does not exist";
            } elseif (! in_array($entry->line, $lines, true)) {
                $stale[] = "{$entry->describe()} names no mutation Pest makes of the source: the code moved or changed, so the entry goes";
            }
        }

        return $stale;
    }

    /**
     * The entries for the source at $path.
     *
     * @return list<EquivalentMutation>
     */
    public function of(string $path): array
    {
        return array_values(array_filter($this->entries, static fn (EquivalentMutation $entry): bool => $entry->path === $path));
    }

    /**
     * The count of the source's mutations without the listed ones, and each entry for the source
     * that is stale: it names no mutation of the run, or names one a test caught.
     *
     * @param  list<MutationOutcome>  $outcomes  every mutation of the source in the run, each once
     */
    public function judge(string $path, array $outcomes): EquivalentJudgement
    {
        $entries = $this->of($path);
        $stale = [];
        $listed = [];

        foreach ($entries as $entry) {
            $matched = array_values(array_filter($outcomes, static fn (MutationOutcome $outcome): bool => $entry->matches($path, $outcome)));

            if ($matched === []) {
                $stale[] = "{$entry->describe()} names no mutation of the run: the code moved or changed, so the entry goes";

                continue;
            }

            foreach ($matched as $outcome) {
                $listed[$outcome->hash] = $outcome;

                if ($outcome->caught) {
                    $stale[] = "{$entry->describe()} is caught by a test, so it is not equivalent and the entry goes";
                }
            }
        }

        $applicable = array_values(array_filter($outcomes, static fn (MutationOutcome $outcome): bool => ! isset($listed[$outcome->hash])));

        return new EquivalentJudgement(
            new MutationCount(count($applicable), count(array_filter($applicable, static fn (MutationOutcome $outcome): bool => $outcome->caught)), count($listed)),
            array_values(array_unique($stale)),
        );
    }
}
