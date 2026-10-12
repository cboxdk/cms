<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Ids\NodeId;
use Override;

/**
 * Version 1 of the payload of node.label_changed: the node and the language. It carries an id and
 * a locale, never the label, which is text; a subscriber that needs the label reads it (PRD 6.5
 * invariant 10).
 */
#[Experimental]
final readonly class NodeLabelChangedV1 implements EventPayload
{
    public function __construct(
        public NodeId $node,
        public Locale $locale,
    ) {}

    #[Override]
    public function data(): EventData
    {
        return EventData::empty()
            ->with('node', EventDatum::identifier($this->node))
            ->with('locale', EventDatum::identifier($this->locale));
    }
}
