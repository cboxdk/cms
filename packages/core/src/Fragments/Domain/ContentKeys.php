<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Fragments\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Events\InvalidEvent;
use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;

/**
 * The content keys a content event affects (PRD 9.4): the keys whose fragments and edge objects
 * an invalidation purges. Every content event of an entry carries the entry's id as its datum
 * "entry", so its key is e-{entry}; the key is content based, not site based, so one purge reaches
 * every site that shows the entry, mounts included.
 */
#[Internal]
final readonly class ContentKeys
{
    /** The datum every content event of an entry carries its entry's id in. */
    public const string ENTRY = 'entry';

    /**
     * @return non-empty-list<DependencyKey>
     *
     * @throws InvalidEvent when the event carries no entry id
     * @throws InvalidUuid7 when the entry id is not a UUIDv7
     */
    public static function of(StoredEvent $event): array
    {
        return [DependencyKey::entry(EntryId::fromString($event->data->get(self::ENTRY)->asIdentifier()->toString()))];
    }
}
