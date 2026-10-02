<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Sessions\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Telemetry\Attribute;
use Cbox\Cms\Contracts\Telemetry\Attributes;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Contracts\Telemetry\TelemetryName;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginMethod;

/**
 * The session counters (GUARDRAILS 5): `cms.session.issued`, 1 for each session issued, with
 * `cms.login.method`, and `cms.session.ended`, the number of sessions ended at once, with
 * `cms.reason`, a SessionEndReason. They carry no session id, actor id or connection, so their
 * cardinality stays that of the methods and the reasons.
 */
#[Internal]
final readonly class SessionCounters
{
    public const string ISSUED = 'cms.session.issued';

    public const string ENDED = 'cms.session.ended';

    public const string METHOD = 'cms.login.method';

    public const string REASON = 'cms.reason';

    public function __construct(private Telemetry $telemetry) {}

    public function issued(LoginMethod $method): void
    {
        $this->telemetry->counter(new CounterRecord(new TelemetryName(self::ISSUED), 1, new Attributes(Attribute::of(self::METHOD, $method->value))));
    }

    /**
     * Counts $sessions sessions ended for the reason; nothing when there were none.
     */
    public function ended(SessionEndReason $reason, int $sessions): void
    {
        if ($sessions < 1) {
            return;
        }

        $this->telemetry->counter(new CounterRecord(new TelemetryName(self::ENDED), $sessions, new Attributes(Attribute::of(self::REASON, $reason->value))));
    }
}
