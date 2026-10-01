<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Core\Pipeline\Domain\ChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Domain\CommandTransaction;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeAffectedProjections;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeChangesetCommitter;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandTransaction;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyAdded;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyRaised;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptSession;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;
use DateInterval;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * ChangesetCommitterBehaviour against the fake the pipeline's action tests use, over a session of
 * the testkit's fake receipt store, with the tally's events and projections.
 */
final class FakeChangesetCommitterBehaviourTest extends TestCase
{
    use ChangesetCommitterBehaviour;

    private const string ACTOR = '01936f5e-8a2b-7c3d-9e4f-0000000000c1';

    private ?FakeClock $clock = null;

    private ?FakeReceiptStore $store = null;

    private ?FakeReceiptSession $session = null;

    private ?FakeChangesetCommitter $committer = null;

    #[Override]
    protected function committer(): ChangesetCommitter
    {
        return $this->committer ??= new FakeChangesetCommitter(
            receipts: $this->session(),
            ids: new FakeIdGenerator(clock: $this->clock()),
            affected: new FakeAffectedProjections([TallyRaised::class => [new ProjectionName(self::TALLY_PROJECTION)]]),
            events: static fn (Mutation $mutation, AggregateVersion $version): array => $mutation instanceof TallyAdded
                ? self::tallyEvents($mutation, $version)
                : throw new InvalidArgumentException(sprintf('The tally harness gives no events for %s.', $mutation::class)),
        );
    }

    #[Override]
    protected function commandTransaction(): CommandTransaction
    {
        return new FakeCommandTransaction($this->session());
    }

    #[Override]
    protected function actor(): ActorId
    {
        return ActorId::fromString(self::ACTOR);
    }

    #[Override]
    protected function uncover(): void
    {
        $now = $this->clock()->now();

        $this->store()->uncover($now->sub(new DateInterval('PT1M')), $now->add(new DateInterval('PT1H')));
    }

    /**
     * Moves the clock, and so the next changeset's time, past the range uncover() took out.
     */
    #[Override]
    protected function cover(): void
    {
        $this->clock()->advance(new DateInterval('PT2H'));
    }

    private function clock(): FakeClock
    {
        return $this->clock ??= new FakeClock;
    }

    private function store(): FakeReceiptStore
    {
        return $this->store ??= new FakeReceiptStore($this->clock());
    }

    private function session(): FakeReceiptSession
    {
        return $this->session ??= $this->store()->session();
    }
}
