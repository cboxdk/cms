<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Adapter;

use Cbox\Cms\Tooling\Mutation\Domain\CaughtByFastSuites;
use Closure;
use JsonException;
use Pest\Mutate\Event\Events\TestSuite\StartMutationSuite;
use Pest\Mutate\Event\Events\TestSuite\StartMutationSuiteSubscriber;
use Pest\Mutate\MutationSuite;
use Pest\Mutate\MutationTest;
use Pest\Mutate\MutationTestCollection;
use RuntimeException;

/**
 * Leaves the mutations the fast suites caught out of the Postgres suite's mutation run of gate 5,
 * when CaughtByFastSuites names them in its file. pest-plugin-mutate emits StartMutationSuite after
 * it made the mutations and before it runs them; this subscriber removes the listed ones from the
 * suite, so neither they run nor its report lists them, and MutationReportReader counts them as
 * caught from the fast suites' ledger. Every other mutation runs as before.
 */
final readonly class SkipCaughtMutations implements StartMutationSuiteSubscriber
{
    /**
     * @param  array<string, list<string>>  $caught  the caught mutations' ids, by path relative to the checkout
     */
    public function __construct(private array $caught) {}

    /**
     * The subscriber for the file the variable names, relative to the working directory, or null
     * without the variable.
     *
     * @throws RuntimeException for a named file that cannot be read as the list of caught mutations
     */
    public static function fromEnvironment(): ?self
    {
        $file = getenv(CaughtByFastSuites::VARIABLE);

        if ($file === false || $file === '') {
            return null;
        }

        $json = is_file($file) ? file_get_contents($file) : false;

        try {
            $data = $json === false ? null : json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = null;
        }

        $files = is_array($data) ? ($data['files'] ?? null) : null;

        if (! is_array($files)) {
            throw new RuntimeException("{$file} does not list the caught mutations, as CaughtByFastSuites writes them.");
        }

        $caught = [];

        foreach ($files as $path => $ids) {
            if (! is_string($path) || ! is_array($ids) || ! array_is_list($ids) || array_filter($ids, is_string(...)) !== $ids) {
                throw new RuntimeException("{$file} does not list the caught mutations, as CaughtByFastSuites writes them.");
            }

            $caught[$path] = array_map(strval(...), $ids);
        }

        return new self($caught);
    }

    public function notify(StartMutationSuite $event): void
    {
        $this->skip($event->mutationSuite);
    }

    /**
     * Removes the caught mutations from the suite and returns how many it removed.
     */
    public function skip(MutationSuite $suite): int
    {
        $directory = realpath((string) getcwd());
        $prefix = $directory === false ? '' : $directory.'/';
        $removed = 0;

        foreach ($suite->repository->all() as $collection) {
            $path = (string) $collection->file->getRealPath();
            $relative = $prefix !== '' && str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
            $ids = array_flip($this->caught[$relative] ?? []);

            if ($ids === []) {
                continue;
            }

            $kept = array_values(array_filter($collection->tests(), static fn (MutationTest $test): bool => ! isset($ids[$test->getId()])));
            $removed += count($collection->tests()) - count($kept);
            Closure::bind(static function (MutationTestCollection $collection) use ($kept): void {
                $collection->tests = $kept;
            }, null, MutationTestCollection::class)($collection);
        }

        return $removed;
    }
}
