<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Idempotency;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
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
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\PrincipalId;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Closure;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * The shared contract suite for IdempotencyStore (GUARDRAILS 2.3 and 9). The fake and every real
 * store run the same cases.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return a
 * harness for a new, empty store from idempotencyStores(). Every store the harness hands out reads
 * the time from the given clock, which the cases move:
 *
 *     final class FakeIdempotencyStoreContractTest extends TestCase
 *     {
 *         use IdempotencyStoreContract;
 *
 *         protected function idempotencyStores(Clock $clock): IdempotencyStoreHarness
 *         {
 *             return new FakeIdempotencyStore($clock);
 *         }
 *     }
 *
 * The cases cover the four results, scopes, logical expiry, a record created on a later UTC day
 * than the claimer's Clock (a Clock that stepped back, or a changeset ahead of it), a record date
 * that no partition covers (through IdempotencyStoreHarness::uncover()) and the claim model: a
 * claim lasts until the caller's transaction ends, complete() writes in that transaction, and a
 * rollback or a commit without complete() leaves the key fresh.
 */
#[Experimental]
trait IdempotencyStoreContract
{
    /**
     * A harness for a new, empty store under test whose sessions read the time from $clock.
     */
    abstract protected function idempotencyStores(Clock $clock): IdempotencyStoreHarness;

    #[Test]
    public function the_first_claim_on_a_key_is_fresh(): void
    {
        $session = $this->idempotencyStores(new FakeClock)->session();
        $session->begin();

        $this->assertFresh($session->idempotency()->claim($this->scope(), $this->key(), $this->hash(), WaitBudget::none()));
        Assert::assertTrue($session->inTransaction(), 'claim() ended the caller\'s transaction.');
    }

    #[Test]
    public function a_completed_and_committed_claim_replays_its_changeset(): void
    {
        $clock = new FakeClock;
        $harness = $this->idempotencyStores($clock);
        $changesetId = new ChangesetId(new FakeIdGenerator(clock: $clock)->next());

        $first = $harness->session();
        $first->begin();
        $first->idempotency()->complete($this->assertFresh($this->claimOn($first)), $changesetId);
        Assert::assertTrue($first->inTransaction(), 'complete() ended the caller\'s transaction.');
        $first->commit();

        $second = $harness->session();
        $second->begin();
        $this->assertReplay($changesetId, $this->claimOn($second));
        Assert::assertTrue($second->inTransaction(), 'A replayed claim ended the caller\'s transaction.');
    }

    #[Test]
    public function the_same_key_with_another_content_hash_is_a_conflict(): void
    {
        $clock = new FakeClock;
        $harness = $this->idempotencyStores($clock);
        $changesetId = $this->completedKey($harness, $clock);

        $session = $harness->session();
        $session->begin();
        $result = $session->idempotency()->claim($this->scope(), $this->key(), ContentHash::of('{"value":"B"}'), WaitBudget::none());

        Assert::assertInstanceOf(Conflict::class, $result, 'The same key with another content hash is not a conflict.');
        Assert::assertTrue($result->scope->equals($this->scope()) && $result->key->equals($this->key()), 'The conflict names another key.');
        Assert::assertSame('idempotency_conflict', Conflict::CODE);
        Assert::assertTrue($session->inTransaction(), 'A conflicting claim ended the caller\'s transaction.');
        $session->rollBack();

        $session->begin();
        $this->assertReplay($changesetId, $this->claimOn($session), 'The conflicting claim changed the stored record.');
    }

    #[Test]
    public function the_same_key_under_another_command_type_or_principal_is_independent(): void
    {
        $clock = new FakeClock;
        $harness = $this->idempotencyStores($clock);
        $changesetId = $this->completedKey($harness, $clock);

        $independent = [
            'another command type' => [IdempotencyScope::forActor(new PrincipalId('user:7'), new CommandName('entry.publish')), $this->key()],
            'another actor' => [IdempotencyScope::forActor(new PrincipalId('user:8'), new CommandName('entry.release')), $this->key()],
            'a source with the same reference' => [IdempotencyScope::forSource(new PrincipalId('user:7'), new CommandName('entry.release')), $this->key()],
            'another key' => [$this->scope(), new IdempotencyKey('01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e10')],
        ];

        foreach ($independent as $what => [$scope, $key]) {
            $session = $harness->session();
            $session->begin();
            $this->assertFresh($session->idempotency()->claim($scope, $key, $this->hash(), WaitBudget::none()), $scope, $key, $this->hash(), "The same key under {$what} is not independent.");
            $session->rollBack();
        }

        $session = $harness->session();
        $session->begin();
        $this->assertReplay($changesetId, $this->claimOn($session));
    }

    #[Test]
    public function a_completed_key_is_fresh_again_seven_days_after_its_changeset(): void
    {
        $clock = new FakeClock;
        $harness = $this->idempotencyStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $changesetId = $this->completedKey($harness, $clock);
        $expiry = $this->changesetTime($changesetId)->add(new DateInterval('P7D'));
        $session = $harness->session();

        foreach (['-1 day' => true, '+0 seconds' => true, '+1 millisecond' => false, '+1 second' => false] as $offset => $live) {
            $clock->set($expiry->modify($offset));
            $session->begin();
            $result = $this->claimOn($session);

            if ($live) {
                $this->assertReplay($changesetId, $result, "The key was not replayed at seven days {$offset}.");
            } else {
                $this->assertFresh($result, message: "The key was not fresh at seven days {$offset}.");
            }

            $session->rollBack();
        }

        $renewed = new ChangesetId($ids->next());
        $session->begin();
        $session->idempotency()->complete($this->assertFresh($this->claimOn($session)), $renewed);
        $session->commit();

        $session->begin();
        $this->assertReplay($renewed, $this->claimOn($session), 'A completed claim on an expired key did not replace the record.');
    }

    #[Test]
    public function a_record_created_on_a_utc_day_after_the_claim_is_replayed(): void
    {
        $clock = new FakeClock;
        $harness = $this->idempotencyStores($clock);
        $midnight = $clock->now()->setTime(0, 0)->add(new DateInterval('P1D'));
        $session = $harness->session();

        // The Clock steps back across midnight between complete() and the retry.
        $clock->set($midnight->modify('+100 milliseconds'));
        $afterMidnight = $this->completedKey($harness, $clock);
        $clock->set($midnight->modify('-100 milliseconds'));
        $session->begin();
        $this->assertReplay($afterMidnight, $this->claimOn($session), 'A record completed after midnight was not replayed for a Clock that stepped back before midnight.');
        $session->rollBack();

        // Another node's clock is days ahead, so the record is created at its changeset's time.
        $ahead = new ChangesetId(new FakeIdGenerator(clock: new FakeClock($midnight->add(new DateInterval('P2DT8H'))))->next());
        $key = new IdempotencyKey('01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e11');
        $session->begin();
        $session->idempotency()->complete($this->assertFresh($session->idempotency()->claim($this->scope(), $key, $this->hash(), WaitBudget::none()), key: $key), $ahead);
        $session->commit();

        $session->begin();
        $this->assertReplay($ahead, $session->idempotency()->claim($this->scope(), $key, $this->hash(), WaitBudget::none()), 'A record whose changeset is days ahead of the Clock was not replayed.');
    }

    #[Test]
    public function a_claim_completed_in_a_rolled_back_transaction_leaves_the_key_fresh(): void
    {
        $clock = new FakeClock;
        $harness = $this->idempotencyStores($clock);
        $writer = $harness->session();
        $reader = $harness->session();

        $writer->begin();
        $writer->idempotency()->complete($this->assertFresh($this->claimOn($writer)), new ChangesetId(new FakeIdGenerator(clock: $clock)->next()));
        $writer->rollBack();

        Assert::assertFalse($writer->inTransaction());
        $reader->begin();
        $this->assertFresh($this->claimOn($reader), message: 'A claim completed in a rolled back transaction left a record or a claim.');
    }

    #[Test]
    public function a_complete_where_no_partition_covers_the_record_throws_partition_missing_and_leaves_the_key_fresh(): void
    {
        $clock = new FakeClock;
        $harness = $this->idempotencyStores($clock);
        $day = $clock->now()->setTime(0, 0)->add(new DateInterval('P2Y'));
        $harness->uncover($day, $day->setTime(23, 59, 59, 999_999));
        $changesetId = new ChangesetId(new FakeIdGenerator(clock: new FakeClock($day->setTime(12, 0, 0, 250_000)))->next());
        $writer = $harness->session();
        $reader = $harness->session();

        // The Clock's day is covered; the later changeset time decides the record's date.
        // Then the Clock is in the uncovered day and later than the changeset.
        foreach (['a changeset time' => $clock->now(), 'a Clock time' => $day->setTime(18, 0)] as $what => $now) {
            $clock->set($now);
            $writer->begin();
            $token = $this->assertFresh($this->claimOn($writer));

            try {
                $writer->idempotency()->complete($token, $changesetId);
                Assert::fail("complete() recorded a key at {$what} that no partition covers.");
            } catch (PartitionMissing $missing) {
                Assert::assertSame('partition_missing', PartitionMissing::CODE);
                Assert::assertStringStartsWith('[partition_missing] ', $missing->getMessage());
            }

            Assert::assertTrue($writer->inTransaction(), 'complete() ended the caller\'s transaction on PartitionMissing.');
            $refused = false;

            try {
                $this->claimOn($writer);
            } catch (Throwable) {
                $refused = true;
            }

            Assert::assertTrue($refused, 'The transaction took another claim after PartitionMissing; it has failed and only rolls back.');
            $writer->rollBack();

            $reader->begin();
            $this->assertFresh($this->claimOn($reader), message: "A complete() at {$what} that no partition covered left a record or a claim.");
            $reader->rollBack();
        }
    }

    #[Test]
    public function a_fresh_claim_committed_without_complete_leaves_the_key_fresh(): void
    {
        $harness = $this->idempotencyStores(new FakeClock);
        $writer = $harness->session();
        $reader = $harness->session();

        $writer->begin();
        $this->assertFresh($this->claimOn($writer));
        $writer->commit();

        $reader->begin();
        $this->assertFresh($this->claimOn($reader), message: 'A claim committed without complete() left a record or a claim.');
    }

    #[Test]
    public function a_claim_held_by_an_open_transaction_is_in_flight_for_another_session(): void
    {
        $harness = $this->idempotencyStores(new FakeClock);
        $holder = $harness->session();
        $waiter = $harness->session();
        $budget = WaitBudget::milliseconds(100);

        $holder->begin();
        $this->assertFresh($this->claimOn($holder));

        $waiter->begin();
        $started = hrtime(true);
        $result = $waiter->idempotency()->claim($this->scope(), $this->key(), $this->hash(), $budget);
        $waited = (hrtime(true) - $started) / 1_000_000;

        Assert::assertInstanceOf(InFlight::class, $result, 'A claim held by another open transaction is not in flight.');
        Assert::assertTrue($result->scope->equals($this->scope()) && $result->key->equals($this->key()), 'The in flight result names another key.');
        Assert::assertSame($budget->milliseconds, $result->waited->milliseconds);
        Assert::assertLessThan($budget->milliseconds + 1000, $waited, 'The claim waited far longer than its budget.');
        Assert::assertTrue($waiter->inTransaction(), 'An in flight claim ended the caller\'s transaction.');

        $this->assertFresh(
            $waiter->idempotency()->claim($this->scope(), new IdempotencyKey('another-key'), $this->hash(), WaitBudget::none()),
            key: new IdempotencyKey('another-key'),
            message: 'The transaction is not usable after an in flight claim.',
        );

        $holder->rollBack();
        $this->assertFresh($this->claimOn($waiter), message: 'The claim is still held after its transaction ended.');
    }

    #[Test]
    public function a_completed_claim_is_in_flight_until_its_transaction_commits(): void
    {
        $clock = new FakeClock;
        $harness = $this->idempotencyStores($clock);
        $holder = $harness->session();
        $waiter = $harness->session();
        $changesetId = new ChangesetId(new FakeIdGenerator(clock: $clock)->next());

        $holder->begin();
        $holder->idempotency()->complete($this->assertFresh($this->claimOn($holder)), $changesetId);

        $waiter->begin();
        Assert::assertInstanceOf(InFlight::class, $this->claimOn($waiter), 'Another session saw an uncommitted record, or the claim was not held.');

        $holder->commit();
        $this->assertReplay($changesetId, $this->claimOn($waiter), 'Another session does not see the committed record.');
    }

    #[Test]
    public function every_result_but_in_flight_holds_the_claim_until_the_transaction_ends(): void
    {
        $clock = new FakeClock;
        $harness = $this->idempotencyStores($clock);
        $changesetId = $this->completedKey($harness, $clock);
        $replaying = $harness->session();
        $conflicting = $harness->session();
        $waiter = $harness->session();
        $waiter->begin();

        $replaying->begin();
        $this->assertReplay($changesetId, $this->claimOn($replaying));
        Assert::assertInstanceOf(InFlight::class, $this->claimOn($waiter), 'A replayed claim did not hold the key.');
        $replaying->commit();

        $conflicting->begin();
        Assert::assertInstanceOf(Conflict::class, $conflicting->idempotency()->claim($this->scope(), $this->key(), ContentHash::of('{"value":"B"}'), WaitBudget::none()));
        Assert::assertInstanceOf(InFlight::class, $this->claimOn($waiter), 'A conflicting claim did not hold the key.');
        $conflicting->rollBack();

        $this->assertReplay($changesetId, $this->claimOn($waiter));
    }

    #[Test]
    public function five_claims_with_the_same_key_and_hash_after_one_completed_commit_all_replay(): void
    {
        $clock = new FakeClock;
        $harness = $this->idempotencyStores($clock);
        $changesetId = $this->completedKey($harness, $clock);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $session = $harness->session();
            $session->begin();
            $this->assertReplay($changesetId, $this->claimOn($session), "Claim {$attempt} of 5 did not replay the changeset.");
            $session->commit();
        }
    }

    #[Test]
    public function the_holding_transaction_sees_its_own_record_before_commit(): void
    {
        $clock = new FakeClock;
        $harness = $this->idempotencyStores($clock);
        $session = $harness->session();
        $changesetId = new ChangesetId(new FakeIdGenerator(clock: $clock)->next());

        $session->begin();
        $token = $this->assertFresh($this->claimOn($session));
        $again = $this->assertFresh($this->claimOn($session), message: 'A second claim before complete() is not fresh.');
        Assert::assertTrue($again->equals($token), 'A second claim before complete() gave another token.');

        $session->idempotency()->complete($token, $changesetId);

        $this->assertReplay($changesetId, $this->claimOn($session), 'The holding transaction does not see its own record.');
        Assert::assertInstanceOf(Conflict::class, $session->idempotency()->claim($this->scope(), $this->key(), ContentHash::of('{"value":"B"}'), WaitBudget::none()));
    }

    #[Test]
    public function complete_needs_a_fresh_claim_held_by_the_transaction(): void
    {
        $clock = new FakeClock;
        $harness = $this->idempotencyStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $holder = $harness->session();
        $other = $harness->session();
        $changesetId = new ChangesetId($ids->next());

        $holder->begin();
        $token = $this->assertFresh($this->claimOn($holder));

        $other->begin();
        $this->assertInvalidClaim(static function () use ($other, $token, $ids): void {
            $other->idempotency()->complete($token, new ChangesetId($ids->next()));
        }, 'Another transaction completed a claim it does not hold.');
        $this->assertInvalidClaim(function () use ($other, $ids): void {
            $other->idempotency()->complete(new ClaimToken($this->scope(), new IdempotencyKey('never-claimed'), $this->hash()), new ChangesetId($ids->next()));
        }, 'A key that was never claimed was completed.');
        Assert::assertTrue($other->inTransaction());
        $other->rollBack();

        $holder->idempotency()->complete($token, $changesetId);
        $this->assertInvalidClaim(static function () use ($holder, $token, $ids): void {
            $holder->idempotency()->complete($token, new ChangesetId($ids->next()));
        }, 'A claim was completed twice.');
        $holder->commit();

        $holder->begin();
        $this->assertInvalidClaim(static function () use ($holder, $token, $ids): void {
            $holder->idempotency()->complete($token, new ChangesetId($ids->next()));
        }, 'A token from an ended transaction was completed.');
        $holder->rollBack();

        $reader = $harness->session();
        $reader->begin();
        $this->assertReplay($changesetId, $this->claimOn($reader), 'A refused complete() changed the record.');
    }

    #[Test]
    public function claim_and_complete_need_an_open_transaction(): void
    {
        $clock = new FakeClock;
        $harness = $this->idempotencyStores($clock);
        $session = $harness->session();
        $changesetId = new ChangesetId(new FakeIdGenerator(clock: $clock)->next());

        $this->assertInvalidClaim(function () use ($session): void {
            $this->claimOn($session);
        }, 'claim() ran without a transaction.');
        $this->assertInvalidClaim(function () use ($session, $changesetId): void {
            $session->idempotency()->complete(new ClaimToken($this->scope(), $this->key(), $this->hash()), $changesetId);
        }, 'complete() ran without a transaction.');
        Assert::assertFalse($session->inTransaction(), 'The store began a transaction.');

        $session->begin();
        $token = $this->assertFresh($this->claimOn($session));
        $session->commit();

        $this->assertInvalidClaim(static function () use ($session, $token, $changesetId): void {
            $session->idempotency()->complete($token, $changesetId);
        }, 'complete() ran after the transaction ended.');

        $reader = $harness->session();
        $reader->begin();
        $this->assertFresh($this->claimOn($reader), message: 'A refused complete() left a record.');
    }

    private function scope(): IdempotencyScope
    {
        return IdempotencyScope::forActor(new PrincipalId('user:7'), new CommandName('entry.release'));
    }

    private function key(): IdempotencyKey
    {
        return new IdempotencyKey('01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0f');
    }

    private function hash(): ContentHash
    {
        return ContentHash::of('{"value":"A"}');
    }

    /**
     * The session's claim on the default scope, key and hash, without waiting.
     */
    private function claimOn(IdempotencyStoreSession $session): ClaimResult
    {
        return $session->idempotency()->claim($this->scope(), $this->key(), $this->hash(), WaitBudget::none());
    }

    /**
     * Completes and commits a claim on the default key in a session of its own, and returns the
     * changeset it recorded.
     */
    private function completedKey(IdempotencyStoreHarness $harness, Clock $clock): ChangesetId
    {
        $changesetId = new ChangesetId(new FakeIdGenerator(seed: 7, clock: $clock)->next());
        $session = $harness->session();
        $session->begin();
        $session->idempotency()->complete($this->assertFresh($this->claimOn($session)), $changesetId);
        $session->commit();

        return $changesetId;
    }

    private function assertFresh(ClaimResult $result, ?IdempotencyScope $scope = null, ?IdempotencyKey $key = null, ?ContentHash $hash = null, string $message = ''): ClaimToken
    {
        Assert::assertInstanceOf(Fresh::class, $result, $message !== '' ? $message : 'The claim is not fresh.');
        $expected = new ClaimToken($scope ?? $this->scope(), $key ?? $this->key(), $hash ?? $this->hash());
        Assert::assertTrue($result->token->equals($expected), 'The fresh claim\'s token names another scope, key or hash.');

        return $result->token;
    }

    private function assertReplay(ChangesetId $expected, ClaimResult $result, string $message = ''): void
    {
        Assert::assertInstanceOf(Replay::class, $result, $message !== '' ? $message : 'The claim is not a replay.');
        Assert::assertSame($expected->toString(), $result->changesetId->toString(), $message !== '' ? $message : 'The replay names another changeset.');
    }

    /**
     * @param  Closure(): void  $call
     */
    private function assertInvalidClaim(Closure $call, string $message): void
    {
        try {
            $call();
        } catch (InvalidClaim) {
            return;
        }

        Assert::fail($message);
    }

    /**
     * The time in the changeset id, computed here and not with RetentionClass::expiresAt(), so a
     * mistake there shows up in this suite.
     */
    private function changesetTime(ChangesetId $changesetId): DateTimeImmutable
    {
        $milliseconds = $changesetId->unixMilliseconds();

        return new DateTimeImmutable(sprintf('@%d.%03d', intdiv($milliseconds, 1000), $milliseconds % 1000));
    }
}
