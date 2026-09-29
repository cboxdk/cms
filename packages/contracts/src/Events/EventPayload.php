<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The payload of an event: a final readonly DTO of one version of the event (GUARDRAILS 2.4,
 * PRD 7.2), with its values as properties and those values as EventData.
 *
 * Its properties hold ids, versions, values that are not text and hashes of text, never content
 * (PRD 6.5 invariant 10): int, bool, null, DateTimeImmutable, a backed enum, an id that implements
 * Ids\Identifier, a TextHash, or a list of these. A string property is refused by the testkit's
 * PHPStan rule cboxCms.eventPayloadText, even one that holds an id or a hash: give it its value
 * object instead.
 */
#[Experimental]
interface EventPayload
{
    /**
     * The payload's values, one field per property.
     */
    public function data(): EventData;
}
