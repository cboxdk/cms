<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Fragments\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Cdn\PurgeMode;
use Cbox\Cms\Contracts\Events\InvalidEvent;
use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Core\Entries\Domain\Events\VariantUnreleased;
use Cbox\Cms\Core\Placements\Domain\Events\PlacementVisibilityChanged;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Structure\Domain\Events\MountOverrideSet;
use Cbox\Cms\Core\Structure\Domain\Events\SiteLocaleRemoved;

/**
 * How a content event is purged at the edge (PRD 8.12 points 3 and 4): softly for a change, which
 * the edge may serve stale while it fetches the new answer, and hard for a removal, which it may
 * never serve again, also not stale-if-error.
 *
 * A removal is a variant.unreleased, whose content the public no longer sees, a
 * placement.visibility_changed to anything but live: hidden by unpublishing, scheduled for later,
 * expired or withdrawn, a site.locale_removed, after which the public resolves nothing below
 * the site's root in the locale, and a mount.override_set that hides the entry on the mount.
 * Every other event of the invalidation is a change.
 */
#[Internal]
final readonly class EdgePurge
{
    /** The datum placement.visibility_changed carries the state after in. */
    public const string VISIBILITY = 'visibility';

    /** The datum mount.override_set carries whether the entry is hidden in. */
    public const string HIDDEN = 'hidden';

    /**
     * @throws InvalidEvent when a placement.visibility_changed carries no state after, or a
     *                      mount.override_set no hidden flag
     */
    public static function modeOf(StoredEvent $event): PurgeMode
    {
        return self::removes($event) ? PurgeMode::Hard : PurgeMode::Soft;
    }

    private static function removes(StoredEvent $event): bool
    {
        return match ($event->type->name) {
            VariantUnreleased::NAME, SiteLocaleRemoved::NAME => true,
            PlacementVisibilityChanged::NAME => $event->data->get(self::VISIBILITY)->asEnumValue() !== Visibility::Live->value,
            MountOverrideSet::NAME => $event->data->get(self::HIDDEN)->asBoolean(),
            default => false,
        };
    }
}
