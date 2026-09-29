---
title: Operations
weight: 27
description: How the kernel runs work that takes longer than a few seconds as an operation in laravel-operations, with progress per chunk, idempotent retries and resume.
---

# Operations

<!-- extension-point: Cbox\Cms\Core\Operations\Domain\ChunkedAction -->
<!-- extension-point: Cbox\Cms\Core\Operations\Domain\OperationRunner -->

Anything that can take more than a few seconds, such as a rebuild of read models, a bulk import or a backfill, is an operation in `cboxdk/laravel-operations` (GUARDRAILS 3, 4.2). The kernel uses the package directly: `CoreServiceProvider` registers its service provider, so its `Operations` contract and the migration of its `operations` table come with the core. No long flow runs in an HTTP request; a console command or a queued job runs it.

The work is a chunked action, `Cbox\Cms\Core\Operations\Domain\ChunkedAction`, and the kernel runs it with `Cbox\Cms\Core\Operations\Domain\OperationRunner`, which the core binds to `PackageOperationRunner`. Both are `#[Experimental]`.

## A chunked action

A chunked action has three methods:

- `kind()` gives an `OperationKind`, such as `entries.rebuild`: lower-case segments of letters, digits and underscores, separated by dots.
- `chunks()` gives a `ChunkPlan`, the chunks in the order they run, each with its own `ChunkName`. The runner asks for it once, when the operation starts.
- `runChunk()` does the work of one chunk.

Two rules hold for every chunk:

- **A chunk is idempotent.** When the process dies after a chunk committed and before the runner recorded it, the next run runs the chunk again. A kernel chunk commits one changeset with an idempotency key derived from the operation key and the chunk name, so the second run replays instead of writing twice.
- **A chunk is short.** Each transaction stays well under 2 seconds (GUARDRAILS 4.1). The runner runs each chunk outside any transaction of its own, and refuses to run inside an open transaction with `OperationInsideTransaction`.

## Running it

`OperationRunner::run()` takes an `OperationRequest`: the action and an `OperationKey` that names this run, such as a date or a batch id. The kind and the key name the operation:

- With no operation for the kind and key, or only a failed one, it starts a new operation with the action's plan. Each chunk is a step of the operation, named by its chunk name.
- With a running one, it resumes it at the first chunk that has not completed, with the plan it started with, even when the data would give another plan now.
- With a completed one, it runs nothing and returns it.

It records each chunk as completed right after the chunk returns, and completes the operation after the last one. It returns an `OperationProgress`: the operation's id, its `OperationState` (running, completed or failed) and the completed and remaining chunks.

When a chunk throws, the exception goes to the caller and the operation stays running, with the chunks before it completed. Running the same request again resumes at that chunk. `OperationRunner::find()` gives the progress of the latest operation of a kind and key, for a command that reports where a run stands.

Two runs of one kind and key never start two operations: finding or starting the operation holds a transaction-scoped advisory lock on the kind and key. Recording a chunk or the completion holds the operation's row lock, so two runs of one operation never overwrite each other's steps.

An operation fails only when the package's stall sweep, `operations:sweep-stalled`, or an operator fails it. The runner starts operations without a deadline, so the sweep leaves them alone, and a failed operation is final: the next run with its kind and key starts a new operation.

## Example

This action works through numbered chunks, and its first run of `chunk-2` fails:

<!-- example-file: examples/Postgres/Operations/NumberedChunks.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Postgres\Operations;

use Cbox\Cms\Core\Operations\Domain\ChunkedAction;
use Cbox\Cms\Core\Operations\Domain\ChunkName;
use Cbox\Cms\Core\Operations\Domain\ChunkPlan;
use Cbox\Cms\Core\Operations\Domain\OperationKind;
use Override;
use RuntimeException;

/**
 * A chunked action that works through numbered chunks. A real one plans its chunks from the data,
 * such as one chunk per thousand rows, and each chunk commits its own changeset with an idempotency
 * key derived from the operation key and the chunk name, so running a chunk twice changes nothing.
 * This one records the chunks it ran, and its first run of chunk-2 fails.
 */
final class NumberedChunks implements ChunkedAction
{
    /** @var list<string> */
    public array $ran = [];

    private bool $failed = false;

    public function __construct(private readonly int $count) {}

    #[Override]
    public function kind(): OperationKind
    {
        return new OperationKind('example.numbered');
    }

    #[Override]
    public function chunks(): ChunkPlan
    {
        return new ChunkPlan(...array_map(
            static fn (int $number): ChunkName => new ChunkName('chunk-'.$number),
            range(1, $this->count),
        ));
    }

    #[Override]
    public function runChunk(ChunkName $chunk): void
    {
        if ($chunk->value === 'chunk-2' && ! $this->failed) {
            $this->failed = true;

            throw new RuntimeException('The database went away for a moment.');
        }

        $this->ran[] = $chunk->value;
    }
}
```

The test runs `NumberedChunks` as an operation on real Postgres. The first run stops at `chunk-2`, and the second resumes there. It is in the `Postgres` suite:

<!-- example: examples/Postgres/Operations/ResumeOperationTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Postgres\Operations;

use Cbox\Cms\Core\Operations\Domain\Dto\OperationRequest;
use Cbox\Cms\Core\Operations\Domain\OperationKey;
use Cbox\Cms\Core\Operations\Domain\OperationKind;
use Cbox\Cms\Core\Operations\Domain\OperationRunner;
use Cbox\Cms\Core\Operations\Domain\OperationState;
use Examples\Postgres\Harness\AddonTestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * A chunked action run as an operation: the run stops at the chunk that fails, the operation stays
 * running with the chunks before it completed, and running the same request again resumes at the
 * failed chunk.
 */
final class ResumeOperationTest extends AddonTestCase
{
    #[Test]
    public function a_second_run_resumes_at_the_chunk_that_failed(): void
    {
        $runner = $this->runner();
        $action = new NumberedChunks(3);
        $request = new OperationRequest($action, new OperationKey('nightly-2031-05-01'));

        try {
            $runner->run($request);
            self::fail('chunk-2 should have failed.');
        } catch (RuntimeException) {
        }

        $stopped = $runner->find(new OperationKind('example.numbered'), new OperationKey('nightly-2031-05-01'));
        self::assertSame(OperationState::Running, $stopped?->state);
        self::assertSame(['chunk-1'], $stopped->completedNames());

        $done = $runner->run($request);

        self::assertSame(OperationState::Completed, $done->state);
        self::assertSame(['chunk-1', 'chunk-2', 'chunk-3'], $done->completedNames());
        self::assertSame(['chunk-1', 'chunk-2', 'chunk-3'], $action->ran);
        self::assertTrue($done->id->equals($stopped->id));
    }

    /**
     * The runner the core binds, on laravel-operations.
     */
    private function runner(): OperationRunner
    {
        return app(OperationRunner::class);
    }
}
```

## What the package does not do yet

`cboxdk/laravel-operations` is pinned at 0.1.0. Its timestamps come from Carbon's clock, not the kernel's `Clock`, and its ids are prefixed ULIDs, not ids from `IdGenerator`. Its `operations` table uses `timestamp` without a time zone and is not partitioned, so finished operations stay until someone removes them. These are recorded for the package's own repository; the kernel does not work around them.
