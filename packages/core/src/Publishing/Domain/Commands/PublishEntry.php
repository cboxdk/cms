<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Publishing\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * Publishes an entry (PRD 6.4), version 1 of entry.publish, the one action behind the panel's
 * primary button: it releases a revision of the entry's shared variant and puts its home placement
 * live in one locale, now or in a window, in one changeset. It takes the entry, the version of its
 * shared variant the caller saw, the revision to release, the home placement, the version of it the
 * caller saw, the locale and the window: null puts the placement live from now, and a window
 * schedules it.
 *
 * The home placement is a placement of the entry below its home node. A type with stages none is
 * public as soon as it is saved, so it has no revision to release: its revision is null, and the
 * command only puts the placement live; every other type names the revision. The single commands
 * variant.release and placement.set_window stay for the advanced cases. A dry run shows every
 * placement that becomes visible, those whose windows were open before the release included. An
 * agent may not publish (invariant 18).
 */
#[CommandName('entry.publish', version: 1)]
#[Experimental]
final readonly class PublishEntry implements ExpectsVersions
{
    public function __construct(
        public EntryId $entry,
        public AggregateVersion $version,
        public ?RevisionNumber $revision,
        public PlacementId $placement,
        public AggregateVersion $placementVersion,
        public Locale $locale,
        public ?TimeWindow $window = null,
    ) {}

    /**
     * The shared variant this command releases a revision of.
     */
    public function variant(): VariantRef
    {
        return new VariantRef($this->entry, VariantKey::shared());
    }

    /**
     * The shared variant and the home placement, at the versions the caller saw.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(
            ReadVersion::at($this->variant(), $this->version),
            ReadVersion::at($this->placement, $this->placementVersion),
        );
    }
}
