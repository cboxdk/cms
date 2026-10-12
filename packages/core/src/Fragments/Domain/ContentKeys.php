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
 * - A node event, which carries no entry, is about the node its datum "node" names: its key is
 *   n-{node}. A move is also about every node below it, which the invalidation reads page by page
 *   (SubtreeNodes), because the event lists no descendant.
 * - A site's locale event carries no entry and the site's root node as its datum "root", so its
 *   key is n-{root}: what the public resolves below the root in the locale changed.
 */
#[Internal]
final readonly class ContentKeys
{
    /** The datum every content event of an entry carries its entry's id in. */
    public const string ENTRY = 'entry';

    /** The datum a placement event carries the node its placement sits under in. */
    public const string NODE = 'node';

    /** The datum a site's locale event carries the site's root node in. */
    public const string ROOT = 'root';

    /**
     * @return non-empty-list<DependencyKey>
     *
     * @throws InvalidEvent when the event carries neither an entry id, a node id nor a site's root node
     * @throws InvalidUuid7 when the entry or node id is not a UUIDv7
     */
    public static function of(StoredEvent $event): array
    {
        $keys = [];

        if ($event->data->has(self::ENTRY)) {
            $keys[] = DependencyKey::entry(EntryId::fromString($event->data->get(self::ENTRY)->asIdentifier()->toString()));
        }

        foreach ([self::NODE, self::ROOT] as $datum) {
            if ($event->data->has($datum)) {
                $keys[] = DependencyKey::node(NodeId::fromString($event->data->get($datum)->asIdentifier()->toString()));
            }
        }

        return $keys === [] ? throw InvalidEvent::missingField(self::ENTRY) : $keys;
    }

    /**
     * The node an event carries in its datum "node".
     *
     * @throws InvalidEvent when the event carries no node id
     * @throws InvalidUuid7 when the node id is not a UUIDv7
     */
    public static function node(StoredEvent $event): NodeId
    {
        return NodeId::fromString($event->data->get(self::NODE)->asIdentifier()->toString());
    }
}
