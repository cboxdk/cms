<?php

declare(strict_types=1);

namespace Examples\Contract\IdempotencyStore;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Idempotency\Conflict;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\Fresh;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Contracts\Idempotency\InFlight;
use Cbox\Cms\Contracts\Idempotency\Replay;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\PrincipalId;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreContract;
use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreHarness;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shared IdempotencyStore suite against CountingIdempotencyStore, through the harness
 * CountingIdempotencyStores, which hands out a CountingIdempotencySession for each session of the
 * decorated store's harness. In the application the decorated store is the default,
 * PostgresIdempotencyStore; here it is the testkit's FakeIdempotencyStore, so the suite runs
 * without services. The class also tests what the decorator adds.
 */
final class CountingIdempotencyStoreContractTest extends TestCase
{
    use IdempotencyStoreContract;

    #[Override]
    protected function idempotencyStores(Clock $clock): IdempotencyStoreHarness
    {
        return new CountingIdempotencyStores(new FakeIdempotencyStore($clock));
    }

    #[Test]
    public function the_decorator_counts_every_claim_by_its_result(): void
    {
        $session = new CountingIdempotencyStores(new FakeIdempotencyStore)->session();
        $store = $session->idempotency();
        $scope = IdempotencyScope::forActor(new PrincipalId('user:7'), new CommandName('entry.release'));
        $key = new IdempotencyKey('0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b');
        $hash = ContentHash::of('{"entry":"42"}');

        $session->begin();
        $claim = $store->claim($scope, $key, $hash, WaitBudget::none());
        $store->complete($claim instanceof Fresh ? $claim->token : throw new LogicException('The first claim is not fresh.'), new ChangesetId(new FakeIdGenerator()->next()));
        $session->commit();

        $session->begin();
        $store->claim($scope, $key, $hash, WaitBudget::none());
        $store->claim($scope, $key, ContentHash::of('{"entry":"43"}'), WaitBudget::none());
        $session->rollBack();

        self::assertSame(
            [Fresh::class => 1, Replay::class => 1, Conflict::class => 1, InFlight::class => 0],
            [
                Fresh::class => $store->claims(Fresh::class),
                Replay::class => $store->claims(Replay::class),
                Conflict::class => $store->claims(Conflict::class),
                InFlight::class => $store->claims(InFlight::class),
            ],
        );
    }
}
