<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Fragments;

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Cache\Fragment;
use Cbox\Cms\Contracts\Cache\FragmentKey;
use Cbox\Cms\Contracts\Cache\FragmentWriteOutcome;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventPosition;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\Subscribers\Delivery;
use Cbox\Cms\Core\Entries\Domain\Events\EntryCreated;
use Cbox\Cms\Core\Entries\Domain\Events\EntryCreatedV1;
use Cbox\Cms\Core\Entries\Domain\Events\VariantRevised;
use Cbox\Cms\Core\Entries\Domain\Events\VariantRevisedV1;
use Cbox\Cms\Core\Fragments\Actions\InvalidateFragments;
use Cbox\Cms\Core\Fragments\Domain\Dto\InvalidationSettings;
use Cbox\Cms\Testkit\Cache\FakeFragmentStore;
use Cbox\Cms\Testkit\Cdn\FakeCdnDriver;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;
use DateInterval;
use DateTimeImmutable;

/**
 * The invalidation subscriber with the fakes of what it uses: a fragment store, a CDN that purges
 * softly or only hard, a receipt store and a clock at 2026-03-10T12:00:00Z. changeset() commits a
 * receipt that lists the projections pending, stored() gives a content event as the log gives it,
 * and write() puts a fragment of an entry's key into the store built at a position.
 */
final readonly class InvalidationWorld
{
    public const string ENTRY = '0192a0c0-0000-7000-8000-0000000001e1';

    public const string OTHER = '0192a0c0-0000-7000-8000-0000000001e2';

    public const string NODE = '0192a0c0-0000-7000-8000-0000000001a2';

    public const string TYPE = '0192a0c0-0000-7000-8000-0000000001c1';

    public FakeClock $clock;

    public FakeFragmentStore $fragments;

    public FakeCdnDriver $cdn;

    public FakeReceiptStore $receipts;

    public InvalidateFragments $subscriber;

    private FakeIdGenerator $ids;

    public function __construct(int $fenceSeconds = 30, bool $softPurge = true)
    {
        $this->clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
        $this->fragments = new FakeFragmentStore($this->clock);
        $this->cdn = new FakeCdnDriver($softPurge);
        $this->receipts = new FakeReceiptStore($this->clock);
        $this->ids = new FakeIdGenerator(clock: $this->clock);
        $this->subscriber = new InvalidateFragments($this->fragments, $this->cdn, $this->receipts, $this->clock, new InvalidationSettings($fenceSeconds));
    }

    public static function entry(string $id = self::ENTRY): EntryId
    {
        return EntryId::fromString($id);
    }

    public static function key(string $id = self::ENTRY): DependencyKey
    {
        return DependencyKey::entry(self::entry($id));
    }

    /**
     * A committed changeset whose receipt lists the projections, pending.
     */
    public function changeset(string ...$projections): ChangesetId
    {
        $changeset = new ChangesetId($this->ids->next());
        $session = $this->receipts->session();
        $session->begin();
        $session->store(new StoredReceipt(
            $changeset,
            RetentionClass::Standard,
            $session->position(),
            array_map(static fn (string $projection): ProjectionStatus => ProjectionStatus::pending(new ProjectionName($projection)), array_values($projections)),
        ));
        $session->commit();

        return $changeset;
    }

    public static function revised(int $version, string $entry = self::ENTRY): VariantRevised
    {
        $id = self::entry($entry);

        return new VariantRevised($version, new VariantRevisedV1($id, new VariantRef($id, VariantKey::shared()), $version, $version > 1 ? $version - 1 : null));
    }

    public static function created(string $entry = self::ENTRY): EntryCreated
    {
        return new EntryCreated(1, new EntryCreatedV1(self::entry($entry), TypeId::fromString(self::TYPE), NodeId::fromString(self::NODE)));
    }

    public function stored(Event $event, ChangesetId $changeset, int $xid): StoredEvent
    {
        return new StoredEvent(
            new EventPosition($xid, $xid * 10),
            $this->clock->now(),
            $changeset,
            EventStream::Interactive,
            1,
            $event->aggregate(),
            $event::type(),
            $event->payload()->data(),
        );
    }

    public function handle(StoredEvent $event, int $attempt = 1): void
    {
        $this->subscriber->handle($event, new Delivery(ActorId::fromString('0192a0c0-0000-7000-8000-00000000000a'), $attempt));
    }

    /**
     * Writes a fragment of the key built at the position, valid for an hour.
     */
    public function write(string $name, int $builtAt, string $entry = self::ENTRY): FragmentWriteOutcome
    {
        return $this->fragments->write(new Fragment(
            new FragmentKey($name),
            'body of '.$name,
            [self::key($entry)],
            new CommitPosition((string) $builtAt),
            $this->clock->now()->add(new DateInterval('PT1H')),
        ));
    }

    public function origin(ChangesetId $changeset): ?ProjectionStatus
    {
        foreach ($this->receipts->find($changeset)->projections ?? [] as $status) {
            if ($status->projection->value === InvalidateFragments::PROJECTION) {
                return $status;
            }
        }

        return null;
    }
}
