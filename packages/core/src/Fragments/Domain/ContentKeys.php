<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Fragments\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Events\InvalidEvent;
use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Contracts\Ids\NodeId;

/**
 * The content keys a content event affects (PRD 9.4): the keys whose fragments and edge objects
 * an invalidation purges.
 *
 * - Every content event of an entry carries the entry's id as its datum "entry", so its key is
 *   e-{entry}; the key is content based, not site based, so one purge reaches every site that shows
 *   the entry, mounts included.
 * - A placement event also carries the node the placement sits under as its datum "node", the node
 *   its slug is looked up under, so its key adds n-{node}. Every answer of a path below that node
 *   depends on it, a 404 of a slug nothing was placed at included (PathAnswers::contentKeys()), and
 *   a mount looks its slugs up below its source, so the mounts that show the node are reached too.
 */
#[Internal]
final readonly class ContentKeys
{
    /** The datum every content event of an entry carries its entry's id in. */
    public const string ENTRY = 'entry';

    /** The datum a placement event carries the node its placement sits under in. */
    public const string NODE = 'node';

    /**
     * @return non-empty-list<DependencyKey>
     *
     * @throws InvalidEvent when the event carries no entry id
     * @throws InvalidUuid7 when the entry or node id is not a UUIDv7
     */
    public static function of(StoredEvent $event): array
    {
        $entry = DependencyKey::entry(EntryId::fromString($event->data->get(self::ENTRY)->asIdentifier()->toString()));

        return $event->data->has(self::NODE)
            ? [$entry, DependencyKey::node(NodeId::fromString($event->data->get(self::NODE)->asIdentifier()->toString()))]
            : [$entry];
    }
}
