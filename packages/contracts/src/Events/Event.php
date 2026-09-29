<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * An event: a notification that a changeset changed an aggregate (PRD 7, GUARDRAILS 2.4).
 *
 * An event is a class. Its type, the name and the payload version, comes from the class, so no
 * event name exists as a string without one; its payload is a versioned DTO. The kernel writes the
 * events of a changeset to the log in the command transaction (PRD 7.3), with the envelope: the
 * changeset, the stream, the time, the failover generation and the transaction's id.
 */
#[Experimental]
interface Event
{
    /**
     * The event's name and payload version, the same for every event of the class.
     */
    public static function type(): EventType;

    /**
     * The aggregate the changeset changed, and the version it left it at.
     */
    public function aggregate(): EventAggregate;

    public function payload(): EventPayload;
}
