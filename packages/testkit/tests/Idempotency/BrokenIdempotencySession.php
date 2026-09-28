<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Idempotency;

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
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\PrincipalId;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencySession;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreSession;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;

/**
 * A session of the fake with one rule broken.
 */
final readonly class BrokenIdempotencySession implements IdempotencyStore, IdempotencyStoreSession
{
    public function __construct(private FakeIdempotencySession $inner, private BrokenIdempotencyState $state, private IdempotencyBreach $breach) {}

    public function idempotency(): IdempotencyStore
    {
        return $this;
    }

    public function begin(): void
    {
        $this->inner->begin();
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollBack(): void
    {
        $this->inner->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    public function claim(IdempotencyScope $scope, IdempotencyKey $key, ContentHash $hash, WaitBudget $waitBudget): ClaimResult
    {
        $this->beginWhenBroken();

        if ($this->breach === IdempotencyBreach::IgnoresScope) {
            $scope = IdempotencyScope::forActor(new PrincipalId('everyone'), new CommandName('any.command'));
        }

        $name = FakeIdempotencyStore::claimName($scope, $key);
        $stuck = $this->state->stuck[$name] ?? null;

        if ($stuck instanceof BrokenIdempotencySession && $stuck !== $this) {
            return new InFlight($scope, $key, $waitBudget);
        }

        if ($this->breach === IdempotencyBreach::GivesUpAtOnce) {
            $result = $this->inner->claim($scope, $key, $hash, WaitBudget::none());

            return $result instanceof InFlight ? new InFlight($scope, $key, $waitBudget) : $result;
        }

        try {
            $result = $this->inner->claim($scope, $key, $hash, $waitBudget);
        } catch (LogicException $failed) {
            if ($this->breach !== IdempotencyBreach::KeepsFailedTransactions) {
                throw $failed;
            }

            return new InFlight($scope, $key, $waitBudget);
        }

        if ($this->breach === IdempotencyBreach::LooksUpToTheEndOfTheClocksDay
            && ($result instanceof Replay || $result instanceof Conflict)
            && ($this->state->createdAt[$name] ?? null) >= $this->state->clock->now()->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0)->modify('+1 day')) {
            return new Fresh(new ClaimToken($scope, $key, $hash));
        }

        if ($this->breach === IdempotencyBreach::IgnoresHash && $result instanceof Conflict && isset($this->state->completed[$name])) {
            return new Replay($this->state->completed[$name]);
        }

        if ($result instanceof Fresh && $this->breach === IdempotencyBreach::KeepsClaimsWithoutComplete) {
            $this->state->stuck[$name] = $this;
        }

        if (($result instanceof Fresh && $this->breach === IdempotencyBreach::ReleasesFreshClaims)
            || (($result instanceof Replay || $result instanceof Conflict) && $this->breach === IdempotencyBreach::ReleasesReplays)) {
            $this->inner->commit();
            $this->inner->begin();
        }

        return $result;
    }

    public function complete(ClaimToken $token, ChangesetId $changesetId): void
    {
        $this->beginWhenBroken();
        $name = FakeIdempotencyStore::claimName($token->scope, $token->key);

        if ($this->breach === IdempotencyBreach::ReleasesFreshClaims) {
            $this->inner->claim($token->scope, $token->key, $token->hash, WaitBudget::none());
        }

        if ($this->breach === IdempotencyBreach::ForgetsRecords) {
            return;
        }

        try {
            $this->inner->complete($token, $changesetId);
        } catch (InvalidClaim $invalid) {
            if ($this->breach !== IdempotencyBreach::AcceptsAnyToken) {
                throw $invalid;
            }

            return;
        }

        $milliseconds = $changesetId->unixMilliseconds();
        $changesetTime = new DateTimeImmutable(sprintf('@%d.%03d', intdiv($milliseconds, 1000), $milliseconds % 1000));
        $this->state->completed[$name] = $changesetId;
        $this->state->createdAt[$name] = max($this->state->clock->now(), $changesetTime);
        unset($this->state->stuck[$name]);

        if ($this->breach === IdempotencyBreach::CommitsCompleteAtOnce) {
            $this->inner->commit();
            $this->inner->begin();
        }
    }

    private function beginWhenBroken(): void
    {
        if ($this->breach === IdempotencyBreach::RunsOutsideTransactions && ! $this->inner->inTransaction()) {
            $this->inner->begin();
        }
    }
}
