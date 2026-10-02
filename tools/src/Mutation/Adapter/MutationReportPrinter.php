<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Adapter;

use Cbox\Cms\Tooling\Mutation\Domain\MutationReportReader;
use Pest\Mutate\Event\Events\TestSuite\FinishMutationSuite;
use Pest\Mutate\Event\Events\TestSuite\FinishMutationSuiteSubscriber;
use Pest\Mutate\MutationTest;
use Pest\Mutate\MutationTestCollection;
use Pest\Mutate\Support\MutationTestResult;
use RuntimeException;

/**
 * Prints the mutation report that MutationReportReader reads, when Pest's mutations are done: one
 * line, the marker and a JSON object with, for each file that has mutations, its path relative
 * to the directory Pest runs in and each mutation's id, whether a test caught it, the line the
 * mutated code starts on and the mutator's class, which name a mutation the same way in every
 * checkout, as the list of equivalent mutations does (EquivalentMutations). Caught
 * counts as Pest's score does: the mutations that failed a test or timed out. The ids let the
 * step with Postgres count what the fast suites' run caught (MutationLedger). The line starts on
 * a line of its own, after the dots of a parallel run.
 */
final readonly class MutationReportPrinter implements FinishMutationSuiteSubscriber
{
    /**
     * @param  resource  $stream
     */
    public function __construct(private mixed $stream) {}

    public function notify(FinishMutationSuite $event): void
    {
        $directory = realpath((string) getcwd());
        $files = [];

        foreach ($event->mutationSuite->repository->all() as $collection) {
            $files[] = $this->file($collection, $directory === false ? '' : $directory.'/');
        }

        usort($files, static fn (array $a, array $b): int => $a['path'] <=> $b['path']);

        $line = "\n".MutationReportReader::MARKER.json_encode(
            ['files' => $files, 'format' => MutationReportReader::FORMAT],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";

        if (fwrite($this->stream, $line) !== strlen($line)) {
            throw new RuntimeException('Cannot print the mutation report.');
        }
    }

    /**
     * @return array{mutations: list<array{caught: bool, id: string, line: int, mutator: string}>, path: string}
     */
    private function file(MutationTestCollection $collection, string $prefix): array
    {
        $path = (string) $collection->file->getRealPath();
        $mutations = array_map(static fn (MutationTest $test): array => [
            'caught' => in_array($test->result(), [MutationTestResult::Tested, MutationTestResult::Timeout], true),
            'id' => $test->getId(),
            'line' => $test->mutation->startLine,
            'mutator' => $test->mutation->mutator,
        ], array_values($collection->tests()));

        usort($mutations, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return [
            'mutations' => $mutations,
            'path' => $prefix !== '' && str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path,
        ];
    }
}
