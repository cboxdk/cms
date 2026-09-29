<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Operations\Fakes;

use Cbox\Cms\Core\Operations\Domain\ChunkName;
use Cbox\Cms\Core\Operations\Domain\Dto\OperationProgress;
use Cbox\Cms\Core\Operations\Domain\Dto\OperationRequest;
use Cbox\Cms\Core\Operations\Domain\OperationId;
use Cbox\Cms\Core\Operations\Domain\OperationKey;
use Cbox\Cms\Core\Operations\Domain\OperationKind;
use Cbox\Cms\Core\Operations\Domain\OperationRunner;
use Cbox\Cms\Core\Operations\Domain\OperationState;
use LogicException;
use Override;

/**
 * An OperationRunner in memory, for the tests of what runs operations. It keeps each operation's
 * plan and completed chunks as PackageOperationRunner keeps them in laravel-operations, and
 * OperationRunnerBehaviour holds the two to the same cases.
 */
final class FakeOperationRunner implements OperationRunner
{
    /** @var list<OperationProgress> */
    private array $operations = [];

    #[Override]
    public function run(OperationRequest $request): OperationProgress
    {
        $kind = $request->action->kind();
        $progress = $this->live($kind, $request->key);

        if (! $progress instanceof OperationProgress) {
            $progress = new OperationProgress(
                new OperationId('op_fake_'.(count($this->operations) + 1)),
                $kind,
                $request->key,
                OperationState::Running,
                [],
                $request->action->chunks()->chunks,
            );
            $this->operations[] = $progress;
        }

        while ($progress->remaining !== []) {
            $chunk = $progress->remaining[0];
            $request->action->runChunk($chunk);
            $progress = $this->replace($progress, [...$progress->completed, $chunk], array_slice($progress->remaining, 1), $progress->state);
        }

        return $progress->state === OperationState::Completed
            ? $progress
            : $this->replace($progress, $progress->completed, [], OperationState::Completed);
    }

    #[Override]
    public function find(OperationKind $kind, OperationKey $key): ?OperationProgress
    {
        $failed = null;

        foreach ($this->mine($kind, $key) as $progress) {
            if ($progress->state !== OperationState::Failed) {
                return $progress;
            }

            $failed = $progress;
        }

        return $failed;
    }

    /**
     * Fails the operation, as laravel-operations' stall sweep or an operator would.
     */
    public function fail(OperationId $id): void
    {
        foreach ($this->operations as $progress) {
            if ($progress->id->equals($id)) {
                $this->replace($progress, $progress->completed, $progress->remaining, OperationState::Failed);

                return;
            }
        }

        throw new LogicException("There is no operation {$id->value}.");
    }

    private function live(OperationKind $kind, OperationKey $key): ?OperationProgress
    {
        foreach ($this->mine($kind, $key) as $progress) {
            if ($progress->state !== OperationState::Failed) {
                return $progress;
            }
        }

        return null;
    }

    /**
     * @return list<OperationProgress>
     */
    private function mine(OperationKind $kind, OperationKey $key): array
    {
        return array_values(array_filter(
            $this->operations,
            static fn (OperationProgress $progress): bool => $progress->kind->equals($kind) && $progress->key->equals($key),
        ));
    }

    /**
     * @param  list<ChunkName>  $completed
     * @param  list<ChunkName>  $remaining
     */
    private function replace(OperationProgress $old, array $completed, array $remaining, OperationState $state): OperationProgress
    {
        $new = new OperationProgress($old->id, $old->kind, $old->key, $state, $completed, $remaining);

        foreach ($this->operations as $index => $progress) {
            if ($progress->id->equals($old->id)) {
                $this->operations[$index] = $new;
            }
        }

        return $new;
    }
}
