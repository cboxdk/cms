<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Fragments\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Subscription;
use Cbox\Cms\Contracts\Cache\DependencyKey;
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
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Contracts\Subscribers\Delivery;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\Subscriber;
use Cbox\Cms\Core\Entries\Domain\Events\EntryCreated;
use Cbox\Cms\Core\Entries\Domain\Events\VariantReleased;
use Cbox\Cms\Core\Entries\Domain\Events\VariantRevised;
use Cbox\Cms\Core\Entries\Domain\Events\VariantUnreleased;
use Cbox\Cms\Core\Fragments\Domain\ContentKeys;
use Cbox\Cms\Core\Fragments\Domain\Dto\InvalidationSettings;
use Cbox\Cms\Core\Fragments\Domain\EdgePurge;
use Cbox\Cms\Core\Fragments\Domain\SubtreeNodes;
use Cbox\Cms\Core\Pipeline\Domain\WaitLevelRule;
use Cbox\Cms\Core\Placements\Domain\Events\PlacementCreated;
use Cbox\Cms\Core\Placements\Domain\Events\PlacementVisibilityChanged;
use Cbox\Cms\Core\Structure\Domain\Events\NodeLabelChanged;
use Cbox\Cms\Core\Structure\Domain\Events\NodeMoved;
use Cbox\Cms\Core\Structure\Domain\Events\SiteLocaleAdded;
use Cbox\Cms\Core\Structure\Domain\Events\SiteLocaleRemoved;
use DateTimeImmutable;
use Override;

/**
 * The invalidation subscriber (PRD 7.6, 8.4, 8.12, 9.4), on the critical lane: for each content
 * event (an entry created, a variant revised, released or unreleased, a placement created or its
 * window set, which covers publishing and unpublishing, a node moved or given a label, a site's
 * locale added or removed) it purges the fragments of the content keys the event affects, then
 * purges the same keys at the edge through the CDN driver, then acknowledges the projection
 * "origin" on the receipt of the event's changeset, so a caller waiting at the wait level origin
 * learns that the server fragments are invalidated.
 *
 * - The fragment purge goes through the store's purge fence at the event's commit position (PRD
 *   8.12 point 1): until the fence ends, the store refuses a fragment of the key that was built by
 *   a read at or below that position, which may not have seen the change. The fence lives for
 *   cbox-cms.fragments.fence_seconds from the Clock's now.
 * - The content keys are the entry's and, for a placement event, the node the placement sits
 *   under (ContentKeys), so an answer of a path nothing was placed at, a 404, is purged when a
 *   placement is created or opened there. A node event's key is the node's; a move is about the
 *   whole subtree, whose nodes the subscriber reads BATCH at a time (SubtreeNodes) and purges
 *   batch by batch, so a move of thousands of nodes holds no list of them in memory and sends the
 *   edge one purge per batch, which the driver splits further by its own limit. For a site's
 *   locale event they are the site's root node's, so what was resolved below the root in the
 *   locale is purged.
 * - The edge purge is soft for a change, which may be served stale while it is rebuilt (PRD 8.12
 *   point 3), and hard for a removal, an unrelease, a placement no longer live or a locale removed, which may never
 *   be served again (point 4, EdgePurge); a driver without soft purges applies every purge hard.
 *   The fragments are purged first, so an edge that refetches gets a fresh origin.
 * - The acknowledgement runs in the runner's batch transaction, so it commits with the
 *   subscription's cursor; a receipt that has expired or does not list the projection is left
 *   alone. When the CDN does not take the purge, handle() throws, the batch rolls back and the
 *   runner tries the event again, so origin is acknowledged only once both purges were taken.
 *
 * Each step is idempotent and a fence never moves down, so an event handled twice, or an older
 * version handled after a newer one, changes nothing more.
 */
#[Subscription(self::NAME, events: [EntryCreated::class, VariantReleased::class, VariantRevised::class, VariantUnreleased::class, PlacementCreated::class, PlacementVisibilityChanged::class, NodeMoved::class, NodeLabelChanged::class, SiteLocaleAdded::class, SiteLocaleRemoved::class], lane: Lane::Critical, projection: self::PROJECTION)]
#[Internal]
final readonly class InvalidateFragments implements Subscriber
{
    public const string NAME = 'fragments.invalidate';

    /** The projection of the wait level origin: server fragments are invalidated (PRD 8.4). */
    public const string PROJECTION = WaitLevelRule::ORIGIN;

    /** How many nodes of a moved subtree are read and purged at a time. */
    public const int BATCH = 200;

    public function __construct(
        private FragmentStore $fragments,
        private CdnDriver $cdn,
        private ReceiptStore $receipts,
        private Clock $clock,
        private InvalidationSettings $settings,
        private SubtreeNodes $subtree,
    ) {}

    /**
     * @throws InvalidEvent when the event carries neither an entry id, a node id nor a site root, or
     *                      a window's event no state after
     * @throws CdnUnavailable when the CDN does not take the purge
     */
    #[Override]
    public function handle(StoredEvent $event, Delivery $delivery): void
    {
        $position = new CommitPosition((string) $event->position->xid);
        $fenceUntil = $this->clock->now()->add($this->settings->fence());
        $mode = EdgePurge::modeOf($event);

        if ($event->type->name === NodeMoved::NAME) {
            $this->purgeSubtree(ContentKeys::node($event), $position, $fenceUntil, $mode);
        } else {
            $this->purge(ContentKeys::of($event), $position, $fenceUntil, $mode);
        }

        $this->receipts->markProjection(
            $event->changesetId,
            ProjectionStatus::acknowledged(new ProjectionName(self::PROJECTION), $this->clock->now()),
        );
    }

    /**
     * Purges the keys of the node and of every node below it, BATCH at a time, the node's own key
     * even when the subtree reads as empty, as it does for a node that is gone meanwhile.
     */
    private function purgeSubtree(NodeId $node, CommitPosition $position, DateTimeImmutable $fenceUntil, PurgeMode $mode): void
    {
        $after = null;
        $purged = false;

        do {
            $page = $this->subtree->below($node, $after, self::BATCH);

            if ($page !== []) {
                $this->purge(array_map(DependencyKey::node(...), $page), $position, $fenceUntil, $mode);
                $after = $page[count($page) - 1];
                $purged = true;
            }
        } while (count($page) === self::BATCH);

        if (! $purged) {
            $this->purge([DependencyKey::node($node)], $position, $fenceUntil, $mode);
        }
    }

    /**
     * Purges the keys from the fragment store, behind the fence, and then from the edge in one
     * purge.
     *
     * @param  non-empty-list<DependencyKey>  $keys
     */
    private function purge(array $keys, CommitPosition $position, DateTimeImmutable $fenceUntil, PurgeMode $mode): void
    {
        foreach ($keys as $key) {
            $this->fragments->purge(new FragmentPurge($key, $position, $fenceUntil));
        }

        $this->cdn->purge(new CdnPurge($keys, $mode));
    }
}
