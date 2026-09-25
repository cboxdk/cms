<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Idempotency;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Idempotency\ClaimResult;
use Cbox\Cms\Contracts\Idempotency\ClaimToken;
use Cbox\Cms\Contracts\Idempotency\Conflict;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\Fresh;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Contracts\Idempotency\InFlight;
use Cbox\Cms\Contracts\Idempotency\InvalidClaim;
use Cbox\Cms\Contracts\Idempotency\Replay;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\IdempotencyStore;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use LogicException;

/**
 * One connection to a FakeIdempotencyStore, and the fake of the IdempotencyStore contract.
 *
 * Inside a transaction the session holds its claims in the store, keeps the tokens of its Fresh
 * claims and its completed records to itself, and reads its own records before the committed
 * ones. Commit writes the records to the store; commit and rollback both release the claims.
 * Without a transaction, claim() and complete() throw InvalidClaim.
 */
#[Experimental]
final class FakeIdempotencySession implements IdempotencyStore, IdempotencyStoreSession
{
    private bool $open = false;

    /** @var array<string, ClaimToken> the token of each Fresh claim not completed yet, by claim name */
    private array $fresh = [];

    /** @var array<string, FakeIdempotencyRecord> records completed in the open transaction, by claim name */
    private array $completed = [];

    public function __construct(private readonly FakeIdempotencyStore $database) {}

    public function idempotency(): IdempotencyStore
    {
        return $this;
    }

    public function begin(): void
    {
        if ($this->open) {
            throw new LogicException('The session already has a transaction open. Nested transactions and savepoints are forbidden (PRD 4.2).');
        }

        $this->open = true;
    }

    public function commit(): void
    {
        if (! $this->open) {
            throw new LogicException('The session has no transaction to commit.');
        }

        $completed = $this->completed;
        $this->end();
        $this->database->commitAndRelease($this, $completed);
    }

    public function rollBack(): void
    {
        if (! $this->open) {
            throw new LogicException('The session has no transaction to roll back.');
        }

        $this->end();
        $this->database->release($this);
    }

    public function inTransaction(): bool
    {
        return $this->open;
    }

    public function claim(IdempotencyScope $scope, IdempotencyKey $key, ContentHash $hash, WaitBudget $waitBudget): ClaimResult
    {
        if (! $this->open) {
            throw InvalidClaim::outsideTransaction('claim');
        }

        $name = FakeIdempotencyStore::claimName($scope, $key);

        if (! $this->database->acquire($name, $this, $waitBudget)) {
            return new InFlight($scope, $key, $waitBudget);
        }

        $record = $this->completed[$name] ?? $this->database->liveRecord($name);

        if (! $record instanceof FakeIdempotencyRecord) {
            $token = new ClaimToken($scope, $key, $hash);
            $this->fresh[$name] = $token;

            return new Fresh($token);
        }

        return $record->hash->equals($hash) ? new Replay($record->changesetId) : new Conflict($scope, $key);
    }

    public function complete(ClaimToken $token, ChangesetId $changesetId): void
    {
        if (! $this->open) {
            throw InvalidClaim::outsideTransaction('complete');
        }

        $name = FakeIdempotencyStore::claimName($token->scope, $token->key);

        if (isset($this->completed[$name])) {
            throw InvalidClaim::alreadyCompleted($token);
        }

        $fresh = $this->fresh[$name] ?? null;

        if (! $fresh instanceof ClaimToken || ! $fresh->equals($token) || ! $this->database->holds($name, $this)) {
            throw InvalidClaim::notHeld($token);
        }

        unset($this->fresh[$name]);
        $this->completed[$name] = new FakeIdempotencyRecord($token->hash, $changesetId);
    }

    private function end(): void
    {
        $this->open = false;
        $this->fresh = [];
        $this->completed = [];
    }
}
