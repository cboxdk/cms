<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Fragments\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Subscription;
use Cbox\Cms\Contracts\Cache\FragmentPurge;
use Cbox\Cms\Contracts\Cache\FragmentStore;
use Cbox\Cms\Contracts\Cdn\CdnDriver;
use Cbox\Cms\Contracts\Cdn\CdnPurge;
use Cbox\Cms\Contracts\Cdn\CdnUnavailable;
use Cbox\Cms\Contracts\Cdn\PurgeMode;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Events\InvalidEvent;
use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Contracts\Subscribers\Delivery;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\Subscriber;
use Cbox\Cms\Core\Entries\Domain\Events\EntryCreated;
use Cbox\Cms\Core\Entries\Domain\Events\VariantRevised;
use Cbox\Cms\Core\Fragments\Domain\ContentKeys;
use Cbox\Cms\Core\Fragments\Domain\Dto\InvalidationSettings;
use Cbox\Cms\Core\Pipeline\Domain\WaitLevelRule;
use Override;

/**
 * The invalidation subscriber (PRD 7.6, 8.4, 8.12, 9.4), on the critical lane: for each content
 * event it purges the fragments of the content keys the event affects, then purges the same keys
 * at the edge through the CDN driver, then acknowledges the projection "origin" on the receipt of
 * the event's changeset, so a caller waiting at the wait level origin learns that the server
 * fragments are invalidated.
 *
 * - The fragment purge goes through the store's purge fence at the event's commit position (PRD
 *   8.12 point 1): until the fence ends, the store refuses a fragment of the key that was built by
 *   a read at or below that position, which may not have seen the change. The fence lives for
 *   cbox-cms.fragments.fence_seconds from the Clock's now.
 * - The edge purge is soft, because every event it receives is a change, which may be served
 *   stale while it is rebuilt (PRD 8.12 point 3); a driver without soft purges applies it hard.
 *   The fragments are purged first, so an edge that refetches gets a fresh origin.
 * - The acknowledgement runs in the runner's batch transaction, so it commits with the
 *   subscription's cursor; a receipt that has expired or does not list the projection is left
 *   alone. When the CDN does not take the purge, handle() throws, the batch rolls back and the
 *   runner tries the event again, so origin is acknowledged only once both purges were taken.
 *
 * Each step is idempotent and a fence never moves down, so an event handled twice, or an older
 * version handled after a newer one, changes nothing more.
 */
#[Subscription(self::NAME, events: [EntryCreated::class, VariantRevised::class], lane: Lane::Critical, projection: self::PROJECTION)]
#[Internal]
final readonly class InvalidateFragments implements Subscriber
{
    public const string NAME = 'fragments.invalidate';

    /** The projection of the wait level origin: server fragments are invalidated (PRD 8.4). */
    public const string PROJECTION = WaitLevelRule::ORIGIN;

    public function __construct(
        private FragmentStore $fragments,
        private CdnDriver $cdn,
        private ReceiptStore $receipts,
        private Clock $clock,
        private InvalidationSettings $settings,
    ) {}

    /**
     * @throws InvalidEvent when the event carries no entry id
     * @throws CdnUnavailable when the CDN does not take the purge
     */
    #[Override]
    public function handle(StoredEvent $event, Delivery $delivery): void
    {
        $keys = ContentKeys::of($event);
        $position = new CommitPosition((string) $event->position->xid);
        $fenceUntil = $this->clock->now()->add($this->settings->fence());

        foreach ($keys as $key) {
            $this->fragments->purge(new FragmentPurge($key, $position, $fenceUntil));
        }

        $this->cdn->purge(new CdnPurge($keys, PurgeMode::Soft));

        $this->receipts->markProjection(
            $event->changesetId,
            ProjectionStatus::acknowledged(new ProjectionName(self::PROJECTION), $this->clock->now()),
        );
    }
}
