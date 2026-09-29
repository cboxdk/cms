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
