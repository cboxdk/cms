<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\Fresh;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Contracts\Idempotency\PrincipalKind;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\PrincipalId;
use Cbox\Cms\Core\IdempotencyStore\Adapter\PostgresIdempotencyStore;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Idempotency\HolderEnd;
use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreHarness;
use Cbox\Cms\Testkit\Postgres\ChildProcesses;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Testkit\Postgres\ProcessContext;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * The shared IdempotencyStoreContract suite's harness for the Postgres store: each session is an
 * independent connection as the app role, with its own backend, and a PostgresIdempotencyStore
 * bound to it. The partitions from the clock's date to 8 days later exist before the first
 * session, because the suite completes claims after moving the clock past the 7-day expiry;
 * uncover() drops the partitions of a range with PartitionGaps.
 */
final readonly class PostgresIdempotencySessions implements IdempotencyStoreHarness
{
    private function __construct(private Clock $clock) {}

    public static function at(Clock $clock): self
    {
        $now = $clock->now();
        app(PartitionFixtures::class)->cover($now, $now->add(new DateInterval('P8D')));

        return new self($clock);
    }

    public function session(): PostgresIdempotencySession
    {
        [$connection] = app(IndependentConnections::class)->open(1);

        return new PostgresIdempotencySession(
            $connection,
            new PostgresIdempotencyStore(app(DatabaseManager::class), $this->clock, $connection->getName()),
        );
    }

    public function uncover(DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        PartitionGaps::open($from, $to);
    }

    /**
     * The holder is a child process as the app role, with a PostgresIdempotencyStore on its own
     * connection at a FakeClock set to this harness's time. It signals `holding` once it holds the
     * claim, sleeps $afterMilliseconds, ends its transaction and signals `ended`. The harness
     * stops the child when the test ends.
     */
    public function holdWhileWaiting(
        IdempotencyScope $scope,
        IdempotencyKey $key,
        ContentHash $hash,
        ?ChangesetId $changesetId,
        HolderEnd $end,
        int $afterMilliseconds,
    ): void {
        // The child gets plain values: they serialise into it without the classes' internals.
        $kind = $scope->kind->value;
        $principal = $scope->principal->value;
        $commandType = $scope->commandType->value;
        $keyValue = $key->value;
        $hashValue = $hash->value;
        $changeset = $changesetId?->toString();
        $commit = $end === HolderEnd::Commit;
        $at = $this->clock->now()->format('Y-m-d\TH:i:s.uP');

        $child = app(ChildProcesses::class)->start(static function (ProcessContext $context) use ($kind, $principal, $commandType, $keyValue, $hashValue, $changeset, $commit, $afterMilliseconds, $at): void {
            $connection = $context->connection();
            $resolver = new ConnectionResolver(['holder' => $connection]);
            $resolver->setDefaultConnection('holder');
            $store = new PostgresIdempotencyStore($resolver, new FakeClock(new DateTimeImmutable($at)));
            $scope = new IdempotencyScope(PrincipalKind::from($kind), new PrincipalId($principal), new CommandName($commandType));

            $connection->beginTransaction();
            $claim = $store->claim($scope, new IdempotencyKey($keyValue), new ContentHash($hashValue), WaitBudget::none());

            if (! $claim instanceof Fresh) {
                throw new LogicException(sprintf('The holder needs a fresh key, and its claim is %s.', $claim::class));
            }

            if ($changeset !== null) {
                $store->complete($claim->token, ChangesetId::fromString($changeset));
            }

            $context->signal('holding');
            usleep($afterMilliseconds * 1000);

            if ($commit) {
                $connection->commit();
            } else {
                $connection->rollBack();
            }

            $context->signal('ended');
        });

        $child->waitForSignal('holding');
    }
}
