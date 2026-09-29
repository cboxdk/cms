<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Operations\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Operations\Domain\ChunkName;
use Cbox\Cms\Core\Operations\Domain\Dto\OperationProgress;
use Cbox\Cms\Core\Operations\Domain\Dto\OperationRequest;
use Cbox\Cms\Core\Operations\Domain\InvalidOperation;
use Cbox\Cms\Core\Operations\Domain\OperationId;
use Cbox\Cms\Core\Operations\Domain\OperationInsideTransaction;
use Cbox\Cms\Core\Operations\Domain\OperationKey;
use Cbox\Cms\Core\Operations\Domain\OperationKind;
use Cbox\Cms\Core\Operations\Domain\OperationRunner;
use Cbox\Cms\Core\Operations\Domain\OperationState;
use Cbox\Operations\Contracts\Operations;
use Cbox\Operations\DataObjects\AdvanceStep;
use Cbox\Operations\DataObjects\CompleteOperation;
use Cbox\Operations\DataObjects\StartOperation;
use Cbox\Operations\Enums\OperationStatus;
use Cbox\Operations\Models\Operation;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * The OperationRunner on laravel-operations, which the kernel uses directly (GUARDRAILS 3). Each
 * chunk is a step of the operation, named by its chunk name; the operation's kind is the action's
 * kind, and its target is the kind and the key (target_type and target_id), which the package
 * indexes.
 *
 * Every write to the operation runs in a short transaction of its own on the operations model's
 * connection, and a chunk runs outside them. Finding or starting the operation of a kind and key
 * holds a transaction-scoped advisory lock on them, so two runs never start two operations for one
 * key; recording a chunk or the completion holds the operation's row lock, so two runs of one
 * operation never overwrite each other's steps. The package's timestamps and ids are its own:
 * Carbon's clock and prefixed ULIDs.
 */
#[Internal]
final readonly class PackageOperationRunner implements OperationRunner
{
    /** Takes the advisory lock of a kind and key, hashed by Postgres to 64 bits. */
    private const string LOCK_KEY = 'select pg_advisory_xact_lock(hashtextextended(?, 0))';

    /** Separates the kind from the key in the lock text; a kind never contains it. */
    private const string LOCK_SEPARATOR = '|';

    public function __construct(
        private Operations $operations,
        private Repository $config,
    ) {}

    #[Override]
    public function run(OperationRequest $request): OperationProgress
    {
        $connection = $this->connection();

        if ($connection->transactionLevel() > 0) {
            throw OperationInsideTransaction::level($connection->transactionLevel());
        }

        $kind = $request->action->kind();
        $progress = $connection->transaction(function () use ($connection, $request, $kind): OperationProgress {
            $connection->select(self::LOCK_KEY, ['cbox-cms.operation:'.$kind->value.self::LOCK_SEPARATOR.$request->key->value]);

            $live = $this->live($kind, $request->key);

            if ($live instanceof Operation) {
                return $this->progress($live);
            }

            return $this->progress($this->operations->start(new StartOperation(
                kind: $kind->value,
                targetType: $kind->value,
                targetId: $request->key->value,
                steps: $request->action->chunks()->names(),
            )));
        });

        foreach ($progress->remaining as $chunk) {
            $request->action->runChunk($chunk);
            $progress = $this->advance($connection, $progress->id, $chunk);
        }

        if ($progress->state === OperationState::Completed) {
            return $progress;
        }

        return $connection->transaction(function () use ($progress): OperationProgress {
            $this->lockRow($progress->id);

            return $this->progress($this->operations->complete(new CompleteOperation($progress->id->value)));
        });
    }

    #[Override]
    public function find(OperationKind $kind, OperationKey $key): ?OperationProgress
    {
        $operation = $this->live($kind, $key) ?? $this->of($kind, $key)
            ->where('status', OperationStatus::Failed->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $operation === null ? null : $this->progress($operation);
    }

    private function advance(Connection $connection, OperationId $id, ChunkName $chunk): OperationProgress
    {
        return $connection->transaction(function () use ($id, $chunk): OperationProgress {
            $this->lockRow($id);

            return $this->progress($this->operations->advanceStep(new AdvanceStep($id->value, $chunk->value)));
        });
    }

    /**
     * The running or completed operation of the kind and key. There is at most one: a new
     * operation starts only when there is none.
     */
    private function live(OperationKind $kind, OperationKey $key): ?Operation
    {
        return $this->of($kind, $key)
            ->whereIn('status', [OperationStatus::Running->value, OperationStatus::Completed->value])
            ->first();
    }

    /**
     * @return Builder<Operation>
     */
    private function of(OperationKind $kind, OperationKey $key): Builder
    {
        return $this->model()::query()
            ->where('kind', $kind->value)
            ->where('target_type', $kind->value)
            ->where('target_id', $key->value);
    }

    private function lockRow(OperationId $id): void
    {
        $this->model()::query()->whereKey($id->value)->lockForUpdate()->firstOrFail();
    }

    private function connection(): Connection
    {
        return new ($this->model())()->getConnection();
    }

    /**
     * The operation model laravel-operations is configured with (`operations.models.operation`).
     *
     * @return class-string<Operation>
     */
    private function model(): string
    {
        $model = $this->config->get('operations.models.operation', Operation::class);

        if (! is_string($model) || ! is_a($model, Operation::class, true)) {
            throw InvalidOperation::unreadable('*', 'the setting [operations.models.operation] does not name a subclass of '.Operation::class);
        }

        return $model;
    }

    private function progress(Operation $operation): OperationProgress
    {
        $completed = [];
        $remaining = [];

        foreach ($operation->steps ?? [] as $step) {
            $name = $step['name'] ?? null;

            if (! is_string($name)) {
                throw InvalidOperation::unreadable($operation->id, 'a step has no name');
            }

            if (($step['status'] ?? null) === OperationStatus::Completed->value) {
                $completed[] = new ChunkName($name);
            } else {
                $remaining[] = new ChunkName($name);
            }
        }

        return new OperationProgress(
            new OperationId($operation->id),
            new OperationKind($operation->kind),
            new OperationKey((string) $operation->target_id),
            $this->state($operation),
            $completed,
            $remaining,
        );
    }

    private function state(Operation $operation): OperationState
    {
        return match ($operation->status) {
            OperationStatus::Running => OperationState::Running,
            OperationStatus::Completed => OperationState::Completed,
            OperationStatus::Failed => OperationState::Failed,
            OperationStatus::Pending => throw InvalidOperation::unreadable($operation->id, 'it is pending, a state the runner never leaves an operation in'),
        };
    }
}
