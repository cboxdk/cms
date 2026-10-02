<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\IdpIdentity;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\Login\UnknownConnection;
use Cbox\Cms\Contracts\Identity\Signals\Audience;
use Cbox\Cms\Contracts\Identity\Signals\EventTypeUri;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventApplied;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventKind;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventOutcome;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventReceiver;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventReplayed;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventToken;
use Cbox\Cms\Contracts\Identity\Signals\SignalAction;
use Cbox\Cms\Contracts\Identity\Signals\SignalErrorCode;
use Cbox\Cms\Contracts\Identity\Signals\SignalId;
use Cbox\Cms\Contracts\Identity\Signals\SignalPin;
use Cbox\Cms\Contracts\Identity\Signals\SignalRefused;
use Cbox\Cms\Contracts\Identity\Signals\SubjectFormat;
use Cbox\Cms\Contracts\Identity\Signals\SubjectIdentifier;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Closure;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared contract suite for SecurityEventReceiver (GUARDRAILS 2.3 and 9, PRD 5.16). The fake
 * and every real receiver run the same cases.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return a
 * harness that builds a receiver on a clock with the pins it is given:
 *
 *     final class FakeSecurityEventReceiverContractTest extends TestCase
 *     {
 *         use SecurityEventReceiverContract;
 *
 *         protected function events(): SecurityEventHarness
 *         {
 *             return new FakeSecurityEventReceiver;
 *         }
 *     }
 *
 * The cases cover the action of each kind of event, a replayed jti, which is acknowledged without
 * an effect, the same jti of another issuer, a refused token that leaves its jti unspent, each
 * refusal of SignalPin::admitEvent(), a subject named only by email or by another issuer, a late
 * delivery, the order of the checks and an unknown connection.
 */
#[Experimental]
trait SecurityEventReceiverContract
{
    /**
     * A harness that builds a receiver with an empty replay store.
     */
    abstract protected function events(): SecurityEventHarness;

    #[Test]
    public function each_kind_of_event_asks_for_its_action(): void
    {
        $receiver = $this->pinnedReceiver(new FakeClock);
        $expected = [
            [SecurityEventKind::SessionRevoked, SignalAction::EndSessions],
            [SecurityEventKind::CredentialChange, SignalAction::EndSessions],
            [SecurityEventKind::AccountDisabled, SignalAction::Deactivate],
            [SecurityEventKind::AccountEnabled, SignalAction::Reactivate],
            [SecurityEventKind::AccountPurged, SignalAction::Deprovision],
            [SecurityEventKind::CredentialCompromise, SignalAction::RevokeCredentials],
        ];

        foreach ($expected as [$kind, $action]) {
            $outcome = $this->applied($receiver->receive($this->connection(), $this->token(event: $kind->type(), jti: 'event-'.$kind->name)));

            Assert::assertSame($kind, $outcome->kind);
            Assert::assertSame($action, $outcome->action());
            Assert::assertTrue($outcome->jti->equals(new SignalId('event-'.$kind->name)));
            Assert::assertTrue($outcome->connection()->equals($this->connection()));
            Assert::assertTrue($outcome->subject->equals(new IdpIdentity($this->connection(), $this->issuer(), $this->subject())));
        }
    }

    #[Test]
    public function a_replayed_jti_is_acknowledged_without_an_effect(): void
    {
        $receiver = $this->pinnedReceiver(new FakeClock);
        $token = $this->token(event: SecurityEventKind::AccountDisabled->type());

        $this->applied($receiver->receive($this->connection(), $token));

        foreach ([$token, $this->token(event: SecurityEventKind::AccountPurged->type())] as $replay) {
            $outcome = $receiver->receive($this->connection(), $replay);

            Assert::assertInstanceOf(SecurityEventReplayed::class, $outcome, 'A jti received before must be SecurityEventReplayed, so the event has one effect.');
            Assert::assertTrue($outcome->jti()->equals(new SignalId('event-1')));
            Assert::assertTrue($outcome->connection()->equals($this->connection()));
        }
    }

    #[Test]
    public function a_jti_is_remembered_by_its_issuer(): void
    {
        $other = new ConnectionId('okta');
        $otherIssuer = new Issuer('https://example.okta.com');
        $receiver = $this->pinnedReceiver(new FakeClock, new SignalPin($other, $otherIssuer, $this->audience()));

        $this->applied($receiver->receive($this->connection(), $this->token()));
        $outcome = $this->applied($receiver->receive($other, $this->token(issuer: $otherIssuer, subject: SubjectIdentifier::issuerAndSubject($otherIssuer, $this->subject()))));

        Assert::assertTrue($outcome->subject->issuer->equals($otherIssuer));
    }

    #[Test]
    public function a_refused_token_does_not_spend_its_jti(): void
    {
        $receiver = $this->pinnedReceiver(new FakeClock);

        $this->expectRefusal(SignalErrorCode::AudienceMismatch, fn () => $receiver->receive($this->connection(), $this->token(audience: [new Audience('another-stream')])));
        $this->expectRefusal(SignalErrorCode::SubjectUnsupported, fn () => $receiver->receive($this->connection(), $this->token(subject: SubjectIdentifier::inFormat(SubjectFormat::Email))));

        $this->applied($receiver->receive($this->connection(), $this->token()));
    }

    #[Test]
    public function a_token_from_another_issuer_is_refused(): void
    {
        $receiver = $this->pinnedReceiver(new FakeClock);

        $this->expectRefusal(SignalErrorCode::IssuerMismatch, fn () => $receiver->receive($this->connection(), $this->token(issuer: new Issuer('https://example.okta.com'))));
    }

    #[Test]
    public function a_token_whose_audience_does_not_list_the_pinned_one_is_refused(): void
    {
        $receiver = $this->pinnedReceiver(new FakeClock);

        $this->expectRefusal(SignalErrorCode::AudienceMismatch, fn () => $receiver->receive($this->connection(), $this->token(audience: [new Audience('another-stream')])));

        $this->applied($receiver->receive($this->connection(), $this->token(audience: [new Audience('another-stream'), $this->audience()])));
    }

    #[Test]
    public function a_token_issued_beyond_the_allowed_skew_is_refused(): void
    {
        $clock = new FakeClock;
        $receiver = $this->pinnedReceiver($clock);

        $this->expectRefusal(SignalErrorCode::IssuedInFuture, fn () => $receiver->receive($this->connection(), $this->token(issuedAt: $clock->now()->add(new DateInterval('PT61S')), jti: 'early')));

        $this->applied($receiver->receive($this->connection(), $this->token(issuedAt: $clock->now()->add(new DateInterval('PT60S')), jti: 'on-time')));
    }

    #[Test]
    public function an_event_delivered_late_is_admitted(): void
    {
        $clock = new FakeClock;
        $receiver = $this->pinnedReceiver($clock);

        $outcome = $this->applied($receiver->receive($this->connection(), $this->token(issuedAt: $clock->now()->sub(new DateInterval('P3D')))));

        Assert::assertSame(SecurityEventKind::SessionRevoked, $outcome->kind);
    }

    #[Test]
    public function an_event_of_another_type_is_refused(): void
    {
        $receiver = $this->pinnedReceiver(new FakeClock);

        $this->expectRefusal(SignalErrorCode::EventUnsupported, fn () => $receiver->receive($this->connection(), $this->token(event: new EventTypeUri('https://schemas.openid.net/secevent/caep/event-type/assurance-level-change'))));
        $this->expectRefusal(SignalErrorCode::EventUnsupported, fn () => $receiver->receive($this->connection(), $this->token(event: EventTypeUri::backChannelLogout())));
    }

    #[Test]
    public function a_subject_not_named_by_issuer_and_subject_is_refused(): void
    {
        $receiver = $this->pinnedReceiver(new FakeClock);

        foreach ([SubjectFormat::Email, SubjectFormat::PhoneNumber, SubjectFormat::Opaque, SubjectFormat::Aliases] as $format) {
            $this->expectRefusal(SignalErrorCode::SubjectUnsupported, fn () => $receiver->receive($this->connection(), $this->token(subject: SubjectIdentifier::inFormat($format))));
        }
    }

    #[Test]
    public function a_subject_of_another_issuer_is_refused(): void
    {
        $receiver = $this->pinnedReceiver(new FakeClock);
        $foreign = SubjectIdentifier::issuerAndSubject(new Issuer('https://example.okta.com'), $this->subject());

        $this->expectRefusal(SignalErrorCode::SubjectUnsupported, fn () => $receiver->receive($this->connection(), $this->token(subject: $foreign)));
    }

    #[Test]
    public function the_checks_run_in_order(): void
    {
        $clock = new FakeClock;
        $receiver = $this->pinnedReceiver($clock);
        $broken = fn (bool $issuer, bool $audience, bool $future, bool $event): SecurityEventToken => $this->token(
            issuer: $issuer ? new Issuer('https://example.okta.com') : $this->issuer(),
            audience: [$audience ? new Audience('another-stream') : $this->audience()],
            issuedAt: $future ? $clock->now()->add(new DateInterval('PT1H')) : null,
            event: $event ? new EventTypeUri('https://example.org/event/other') : null,
            subject: SubjectIdentifier::inFormat(SubjectFormat::Email),
        );

        $this->expectRefusal(SignalErrorCode::IssuerMismatch, fn () => $receiver->receive($this->connection(), $broken(true, true, true, true)));
        $this->expectRefusal(SignalErrorCode::AudienceMismatch, fn () => $receiver->receive($this->connection(), $broken(false, true, true, true)));
        $this->expectRefusal(SignalErrorCode::IssuedInFuture, fn () => $receiver->receive($this->connection(), $broken(false, false, true, true)));
        $this->expectRefusal(SignalErrorCode::EventUnsupported, fn () => $receiver->receive($this->connection(), $broken(false, false, false, true)));
        $this->expectRefusal(SignalErrorCode::SubjectUnsupported, fn () => $receiver->receive($this->connection(), $broken(false, false, false, false)));
    }

    #[Test]
    public function a_connection_without_a_pin_is_unknown(): void
    {
        $receiver = $this->pinnedReceiver(new FakeClock);

        try {
            $receiver->receive(new ConnectionId('okta'), $this->token());
        } catch (UnknownConnection $unknown) {
            Assert::assertStringContainsString('okta', $unknown->getMessage());

            return;
        }

        Assert::fail('An event for an unknown connection was received; it must throw UnknownConnection.');
    }

    private function pinnedReceiver(FakeClock $clock, SignalPin ...$more): SecurityEventReceiver
    {
        return $this->events()->receiver($clock, new SignalPin($this->connection(), $this->issuer(), $this->audience()), ...$more);
    }

    private function applied(SecurityEventOutcome $outcome): SecurityEventApplied
    {
        Assert::assertInstanceOf(SecurityEventApplied::class, $outcome, 'The first delivery of a jti must be SecurityEventApplied.');

        return $outcome;
    }

    private function connection(): ConnectionId
    {
        return new ConnectionId('entra-acme');
    }

    private function issuer(): Issuer
    {
        return new Issuer('https://ssf.example.org');
    }

    private function audience(): Audience
    {
        return new Audience('https://cms.example.org/ssf');
    }

    private function subject(): Subject
    {
        return new Subject('5f3c1a0e-1d2b-4c5d-9e8f-0a1b2c3d4e5f');
    }

    /**
     * A session-revoked event of the pinned issuer for the pinned audience about the subject, issued
     * a minute before the fake clock's start, unless an argument says otherwise.
     *
     * @param  list<Audience>|null  $audience
     */
    private function token(
        ?Issuer $issuer = null,
        ?array $audience = null,
        ?DateTimeImmutable $issuedAt = null,
        string $jti = 'event-1',
        ?EventTypeUri $event = null,
        ?SubjectIdentifier $subject = null,
    ): SecurityEventToken {
        return new SecurityEventToken(
            $issuer ?? $this->issuer(),
            $audience ?? [$this->audience()],
            $issuedAt ?? new DateTimeImmutable(FakeClock::START)->sub(new DateInterval('PT1M')),
            new SignalId($jti),
            $event ?? SecurityEventKind::SessionRevoked->type(),
            $subject ?? SubjectIdentifier::issuerAndSubject($this->issuer(), $this->subject()),
        );
    }

    /**
     * @param  Closure(): object  $receive
     */
    private function expectRefusal(SignalErrorCode $reason, Closure $receive): void
    {
        try {
            $receive();
        } catch (SignalRefused $refused) {
            Assert::assertSame($reason, $refused->reason);

            return;
        }

        Assert::fail(sprintf('The event was received; it must be refused with %s.', $reason->value));
    }
}
