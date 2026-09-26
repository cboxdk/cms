<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Adapter;

use Cbox\Cms\Tooling\Mutation\Domain\MutationReportReader;
use Pest\Mutate\Event\Events\TestSuite\FinishMutationSuite;
use Pest\Mutate\Event\Events\TestSuite\FinishMutationSuiteSubscriber;
use Pest\Mutate\MutationTestCollection;
use RuntimeException;

/**
 * Prints the mutation report that MutationReportReader reads, when Pest's mutations are done: one
 * line, the marker and a JSON object with, for each file that has mutations, its path relative
 * to the directory Pest runs in, the number of mutations, and how many a test caught. Caught
 * counts as Pest's score does: the mutations that failed a test or timed out. The line starts on
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
     * @return array{caught: int, mutations: int, path: string}
     */
    private function file(MutationTestCollection $collection, string $prefix): array
    {
        $path = (string) $collection->file->getRealPath();

        return [
            'caught' => $collection->tested() + $collection->timedOut(),
            'mutations' => $collection->count(),
            'path' => $prefix !== '' && str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path,
        ];
    }
}
