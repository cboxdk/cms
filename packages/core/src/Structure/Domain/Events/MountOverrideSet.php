<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventAggregate;
use Cbox\Cms\Contracts\Events\EventType;
use Override;

/**
 * A site's exception to a mount was set for an entry, mount.override_set version 1 (PRD 5.8,
 * 7.13): the mount node at the version the override gave it, with the entry and whether the site
 * hides it on the mount. The invalidation purges the e- key of the entry and the n- key of the
 * mount node, hard when the entry is hidden and softly when it is shown again.
 */
#[Experimental]
final readonly class MountOverrideSet implements Event
{
    public const string NAME = 'mount.override_set';

    /** The type of the aggregate the event is about. */
    public const string AGGREGATE = 'node';

    public function __construct(
        private int $version,
        private MountOverrideSetV1 $payload,
    ) {}

    #[Override]
    public static function type(): EventType
    {
        return new EventType(self::NAME, 1);
    }

    #[Override]
    public function aggregate(): EventAggregate
    {
        return new EventAggregate(new AggregateType(self::AGGREGATE), $this->payload->node, $this->version);
    }

    #[Override]
    public function payload(): MountOverrideSetV1
    {
        return $this->payload;
    }
}
