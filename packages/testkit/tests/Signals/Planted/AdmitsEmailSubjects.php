<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Signals\Planted;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventOutcome;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventReceiver;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventToken;
use Cbox\Cms\Contracts\Identity\Signals\SignalPin;
use Cbox\Cms\Contracts\Identity\Signals\SubjectFormat;
use Cbox\Cms\Contracts\Identity\Signals\SubjectIdentifier;
use Cbox\Cms\Testkit\Signals\FakeSecurityEventReceiver;
use Override;

/**
 * A planted receiver that takes a subject named by email as if it were the subject of the pinned
 * issuer, as a receiver that looked actors up by email address would.
 */
final readonly class AdmitsEmailSubjects implements SecurityEventReceiver
{
    private FakeSecurityEventReceiver $fake;

    /** @var array<string, SignalPin> */
    private array $pins;

    public function __construct(Clock $clock, SignalPin ...$pins)
    {
        $this->fake = new FakeSecurityEventReceiver($clock, ...$pins);
        $byConnection = [];

        foreach ($pins as $pin) {
            $byConnection[$pin->connection->value] = $pin;
        }

        $this->pins = $byConnection;
    }

    #[Override]
    public function receive(ConnectionId $connection, SecurityEventToken $token): SecurityEventOutcome
    {
        $pin = $this->pins[$connection->value] ?? null;

        if (! $pin instanceof SignalPin || $token->subject->format !== SubjectFormat::Email) {
            return $this->fake->receive($connection, $token);
        }

        return $this->fake->receive($connection, new SecurityEventToken(
            $token->issuer,
            $token->audience,
            $token->issuedAt,
            $token->jti,
            $token->event,
            SubjectIdentifier::issuerAndSubject($pin->issuer, new Subject('looked-up-by-email')),
        ));
    }
}
