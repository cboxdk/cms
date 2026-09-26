<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\IdempotencyStore\Adapter;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
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
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Core\Partitions\Adapter\MissingPartitionMapper;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use LogicException;

/**
 * The idempotency store on Postgres (PRD 6.1, 4, 4.1), as the app role.
 *
 * It runs every statement on the caller's connection, the default connection unless one is named,
 * inside the caller's open command transaction, and never begins, commits or rolls back one or
 * uses a savepoint (GUARDRAILS 4.1, PRD 4.2). It makes no call outside Postgres.
 *
 * Records live in `idempotency_keys`, RANGE-partitioned per day on created_at; see the migration.
 * A unique index on a partitioned table must contain the partition key (PRD 4.1), so Postgres
 * cannot keep one record per key across the daily partitions. The claim does it instead:
 *
 * 1. The claim is a transaction-scoped advisory lock on ClaimLock::of($scope, $key), a 64-bit hash
 *    of the scope and key. It is taken with pg_try_advisory_xact_lock, polled with a short,
 *    growing sleep until it is granted or the wait budget has passed, which gives InFlight. The
 *    store never blocks in Postgres: a lock_timeout error would abort the caller's transaction,
 *    and getting it back needs a savepoint, which PRD 4.2 forbids. The lock is released when the
 *    transaction ends, so there is no release(). The same statement checks the isolation level
 *    and takes no lock unless it is READ COMMITTED.
 * 2. The lookup is a separate statement after the lock. Under READ COMMITTED each statement takes
 *    a new snapshot, so it sees a record that the previous holder committed while this claim
 *    waited. It matches the full scope and key, not only the hash, so two keys whose hashes
 *    collide only wait for each other. It reads the live record inside the window of 7 days.
 * 3. A Fresh claim is noted in a transaction-local setting (ClaimsInTransaction), which complete()
 *    checks. Postgres resets it when the transaction ends, together with the lock.
 *
 * complete() inserts the record. created_at, the partition key, is the Clock's time, or the
 * changeset's time when that is later, so a record is never created before its changeset. Its
 * expiry is RetentionClass::Standard->expiresAt(), the changeset's time plus 7 days, so the record
 * never outlives the receipt a Replay points to. Together these bound every live record to
 * created_at >= now - 7 days: the lookup scans at most the 8 daily partitions from 7 days ago up
 * to today, and the partition manager drops a partition a week after its day ends.
 *
 * A write at a date with no partition throws PartitionMissing.
 */
#[Experimental]
final readonly class PostgresIdempotencyStore implements IdempotencyStore
{
    public const string TABLE = 'idempotency_keys';

    /** The transaction-local setting that holds the open transaction's Fresh claims. */
    public const string CLAIMS_SETTING = 'cbox_cms.idempotency_claims';

    /** The first sleep between two tries of the lock, in microseconds; each sleep doubles. */
    private const int FIRST_BACKOFF_MICROSECONDS = 2_000;

    /** The longest sleep between two tries of the lock, in microseconds. */
    private const int MAX_BACKOFF_MICROSECONDS = 50_000;

    private const string READ_COMMITTED = 'read committed';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection, the one
     *                                   the command kernel opens its transaction on
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private Clock $clock,
        private ?string $connection = null,
    ) {}

    /**
     * @throws InvalidClaim when the connection has no transaction open or is not at READ COMMITTED
     */
    public function claim(IdempotencyScope $scope, IdempotencyKey $key, ContentHash $hash, WaitBudget $waitBudget): ClaimResult
    {
        $db = $this->db();

        if ($db->transactionLevel() < 1) {
            throw InvalidClaim::outsideTransaction('claim');
        }

        $lock = ClaimLock::of($scope, $key);

        if (! $this->acquire($db, $lock, $waitBudget)) {
            return new InFlight($scope, $key, $waitBudget);
        }

        $record = $this->liveRecord($db, $lock, $scope, $key);

        if ($record instanceof IdempotencyRecord) {
            return $record->hash->equals($hash) ? new Replay($record->changesetId) : new Conflict($scope, $key);
        }

        $this->remember($db, $this->claims($db)->withFresh($lock, $hash));

        return new Fresh(new ClaimToken($scope, $key, $hash));
    }

    /**
     * @throws InvalidClaim when the connection has no transaction open, or it holds no Fresh claim
     *                      for the token that is not completed yet
     * @throws PartitionMissing when no partition covers the record's created_at
     */
    public function complete(ClaimToken $token, ChangesetId $changesetId): void
    {
        $db = $this->db();

        if ($db->transactionLevel() < 1) {
            throw InvalidClaim::outsideTransaction('complete');
        }

        $lock = ClaimLock::of($token->scope, $token->key);
        $claims = $this->claims($db);

        if ($claims->isCompleted($lock)) {
            throw InvalidClaim::alreadyCompleted($token);
        }

        if (! $claims->isFresh($lock, $token->hash)) {
            throw InvalidClaim::notHeld($token);
        }

        $expiresAt = RetentionClass::Standard->expiresAt($changesetId)
            ?? throw new LogicException('A Standard receipt always expires.');
        $milliseconds = $changesetId->unixMilliseconds();
        $changesetTime = new DateTimeImmutable(sprintf('@%d.%03d', intdiv($milliseconds, 1000), $milliseconds % 1000));
        $now = $this->clock->now();

        try {
            $db->table(self::TABLE)->insert([
                'lock_key' => $lock->key,
                'principal_kind' => $token->scope->kind->value,
                'principal' => $token->scope->principal->value,
                'command_type' => $token->scope->commandType->value,
                'idempotency_key' => $token->key->value,
                'content_hash' => $token->hash->value,
                'changeset_id' => $changesetId->toString(),
                'expires_at' => $this->timestamp($expiresAt),
                'created_at' => $this->timestamp(max($now, $changesetTime)),
            ]);
        } catch (QueryException $exception) {
            throw MissingPartitionMapper::map($exception);
        }

        $this->remember($db, $claims->withCompleted($lock));
    }

    /**
     * The lookup window for the Clock's time $now: from $now minus 7 days, the lowest created_at a
     * live record can have, to the end of $now's UTC day. The end is a bound for partition pruning;
     * it lets a record created later on the same day, by a clock that stepped back, still be found.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable} the inclusive start and the exclusive end
     */
    public static function window(DateTimeImmutable $now): array
    {
        $utc = $now->setTimezone(new DateTimeZone('UTC'));

        return [
            $utc->sub(new DateInterval(sprintf('P%dD', RetentionClass::STANDARD_DAYS))),
            $utc->setTime(0, 0)->add(new DateInterval('P1D')),
        ];
    }

    /**
     * Tries the advisory lock until it is granted or the budget has passed. Real time, measured
     * here; the Clock only decides expiry.
     */
    private function acquire(ConnectionInterface $db, ClaimLock $lock, WaitBudget $budget): bool
    {
        $deadline = hrtime(true) + $budget->milliseconds * 1_000_000;
        $backoff = self::FIRST_BACKOFF_MICROSECONDS;

        while (true) {
            // No lock is taken outside READ COMMITTED; CASE evaluates the lock only in its branch.
            $row = $db->selectOne(
                "select current_setting('transaction_isolation') as isolation, case when current_setting('transaction_isolation') = ? then pg_try_advisory_xact_lock(?) end as locked",
                [self::READ_COMMITTED, $lock->key],
                false,
            );
            $isolation = is_object($row) && property_exists($row, 'isolation') ? $row->isolation : null;
            $locked = is_object($row) && property_exists($row, 'locked') ? $row->locked : null;

            if ($isolation !== self::READ_COMMITTED) {
                throw InvalidClaim::isolationLevel(is_string($isolation) ? $isolation : 'unknown');
            }

            if ($locked === true) {
                return true;
            }

            $remaining = intdiv($deadline - hrtime(true), 1000);

            if ($remaining <= 0) {
                return false;
            }

            usleep(min($backoff, $remaining));
            $backoff = min($backoff * 2, self::MAX_BACKOFF_MICROSECONDS);
        }
    }

    /**
     * The live record for the claim, as this transaction sees it: its own uncommitted record, or
     * the latest committed one.
     */
    private function liveRecord(ConnectionInterface $db, ClaimLock $lock, IdempotencyScope $scope, IdempotencyKey $key): ?IdempotencyRecord
    {
        $now = $this->clock->now();
        [$from, $until] = self::window($now);

        $row = $db->table(self::TABLE)
            ->useWritePdo()
            ->select(['content_hash', 'changeset_id'])
            ->where('lock_key', $lock->key)
            ->where('principal_kind', $scope->kind->value)
            ->where('principal', $scope->principal->value)
            ->where('command_type', $scope->commandType->value)
            ->where('idempotency_key', $key->value)
            ->where('created_at', '>=', $this->timestamp($from))
            ->where('created_at', '<', $this->timestamp($until))
            ->where('expires_at', '>=', $this->timestamp($now))
            ->orderByDesc('expires_at')
            ->first();

        return is_object($row) ? IdempotencyRecord::fromRow($row) : null;
    }

    private function claims(ConnectionInterface $db): ClaimsInTransaction
    {
        $row = $db->selectOne('select current_setting(?, true) as claims', [self::CLAIMS_SETTING], false);
        $claims = is_object($row) && property_exists($row, 'claims') ? $row->claims : null;

        return ClaimsInTransaction::parse(is_string($claims) ? $claims : null);
    }

    private function remember(ConnectionInterface $db, ClaimsInTransaction $claims): void
    {
        $db->selectOne('select set_config(?, ?, true)', [self::CLAIMS_SETTING, $claims->toSetting()], false);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }

    private function timestamp(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
