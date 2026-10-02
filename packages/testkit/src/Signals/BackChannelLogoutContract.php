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
use Cbox\Cms\Contracts\Identity\Signals\BackChannelLogoutReceiver;
use Cbox\Cms\Contracts\Identity\Signals\EventTypeUri;
use Cbox\Cms\Contracts\Identity\Signals\IdpSessionId;
use Cbox\Cms\Contracts\Identity\Signals\LogoutScope;
use Cbox\Cms\Contracts\Identity\Signals\LogoutToken;
use Cbox\Cms\Contracts\Identity\Signals\SignalErrorCode;
use Cbox\Cms\Contracts\Identity\Signals\SignalId;
use Cbox\Cms\Contracts\Identity\Signals\SignalPin;
use Cbox\Cms\Contracts\Identity\Signals\SignalRefused;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Closure;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared contract suite for BackChannelLogoutReceiver (GUARDRAILS 2.3 and 9, PRD 5.16). The
 * fake and every real receiver run the same cases.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return a
 * harness that builds a receiver on a clock with the pins it is given:
 *
 *     final class FakeBackChannelLogoutContractTest extends TestCase
 *     {
 *         use BackChannelLogoutContract;
 *
 *         protected function logouts(): BackChannelLogoutHarness
 *         {
 *             return new FakeBackChannelLogoutReceiver;
 *         }
 *     }
 *
 * The cases cover the sessions a logout ends by sid and by subject, a replayed jti, which is refused
 * and so has one effect, the same jti of another issuer, a refused token that leaves its jti
 * unspent, each refusal of SignalPin::admitLogout() at its boundary, the order of the checks and an
 * unknown connection.
 */
#[Experimental]
trait BackChannelLogoutContract
{
    /**
     * A harness that builds a receiver with an empty replay store.
     */
    abstract protected function logouts(): BackChannelLogoutHarness;

    #[Test]
    public function a_logout_with_a_sid_ends_the_sessions_of_that_idp_session(): void
    {
        $receiver = $this->pinnedReceiver(new FakeClock);

        $outcome = $receiver->receive($this->connection(), $this->token(subject: $this->subject(), session: $this->session()));

        Assert::assertSame(LogoutScope::IdpSession, $outcome->scope);
        Assert::assertTrue($outcome->connection->equals($this->connection()));
        Assert::assertTrue($outcome->issuer->equals($this->issuer()));
        Assert::assertTrue($outcome->jti->equals(new SignalId('logout-1')));
        Assert::assertNotNull($outcome->session);
        Assert::assertTrue($outcome->session->equals($this->session()));
        Assert::assertNotNull($outcome->identity());
        Assert::assertTrue($outcome->identity()->equals(new IdpIdentity($this->connection(), $this->issuer(), $this->subject())));
    }

    #[Test]
    public function a_logout_with_only_a_subject_ends_every_session_of_the_subject(): void
    {
        $receiver = $this->pinnedReceiver(new FakeClock);

        $outcome = $receiver->receive($this->connection(), $this->token(subject: $this->subject(), session: null));

        Assert::assertSame(LogoutScope::Subject, $outcome->scope);
        Assert::assertNull($outcome->session);
        Assert::assertNotNull($outcome->identity());
        Assert::assertTrue($outcome->identity()->equals(new IdpIdentity($this->connection(), $this->issuer(), $this->subject())));
    }

    #[Test]
    public function a_logout_with_only_a_sid_ends_that_idp_session(): void
    {
        $receiver = $this->pinnedReceiver(new FakeClock);

        $outcome = $receiver->receive($this->connection(), $this->token(subject: null, session: $this->session()));

        Assert::assertSame(LogoutScope::IdpSession, $outcome->scope);
        Assert::assertNull($outcome->subject);
        Assert::assertNull($outcome->identity());
    }

    #[Test]
    public function a_replayed_jti_is_refused_so_the_logout_has_one_effect(): void
    {
        $receiver = $this->pinnedReceiver(new FakeClock);
        $token = $this->token();

        $receiver->receive($this->connection(), $token);

        $this->expectRefusal(SignalErrorCode::Replayed, fn () => $receiver->receive($this->connection(), $token));
        $this->expectRefusal(SignalErrorCode::Replayed, fn () => $receiver->receive($this->connection(), $this->token(subject: new Subject('another-subject'), session: null)));
    }

    #[Test]
    public function a_jti_is_remembered_by_its_issuer(): void
    {
        $other = new ConnectionId('okta');
        $otherIssuer = new Issuer('https://example.okta.com');
        $receiver = $this->pinnedReceiver(new FakeClock, new SignalPin($other, $otherIssuer, $this->audience()));

        $receiver->receive($this->connection(), $this->token());
        $outcome = $receiver->receive($other, $this->token(issuer: $otherIssuer));

        Assert::assertTrue($outcome->issuer->equals($otherIssuer));
        $this->expectRefusal(SignalErrorCode::Replayed, fn () => $receiver->receive($other, $this->token(issuer: $otherIssuer)));
    }

    #[Test]
    public function a_refused_token_does_not_spend_its_jti(): void
    {
        $receiver = $this->pinnedReceiver(new FakeClock);

        $this->expectRefusal(SignalErrorCode::AudienceMismatch, fn () => $receiver->receive($this->connection(), $this->token(audience: [new Audience('another-client')])));
        $this->expectRefusal(SignalErrorCode::NoncePresent, fn () => $receiver->receive($this->connection(), $this->token(nonce: true)));

        Assert::assertSame(LogoutScope::IdpSession, $receiver->receive($this->connection(), $this->token())->scope);
    }

    #[Test]
    public function a_token_from_another_issuer_is_refused(): void
    {
        $receiver = $this->pinnedReceiver(new FakeClock);

        $this->expectRefusal(SignalErrorCode::IssuerMismatch, fn () => $receiver->receive($this->connection(), $this->token(issuer: new Issuer('https://example.okta.com'))));
        $this->expectRefusal(SignalErrorCode::IssuerMismatch, fn () => $receiver->receive($this->connection(), $this->token(issuer: new Issuer('https://id.example.org/'))));
    }

    #[Test]
    public function a_token_whose_audience_does_not_list_the_pinned_one_is_refused(): void
    {
        $receiver = $this->pinnedReceiver(new FakeClock);

        $this->expectRefusal(SignalErrorCode::AudienceMismatch, fn () => $receiver->receive($this->connection(), $this->token(audience: [new Audience('another-client')])));
        $this->expectRefusal(SignalErrorCode::AudienceMismatch, fn () => $receiver->receive($this->connection(), $this->token(audience: [new Audience('CMS-CLIENT')])));

        $outcome = $receiver->receive($this->connection(), $this->token(audience: [new Audience('another-client'), $this->audience()]));

        Assert::assertTrue($outcome->jti->equals(new SignalId('logout-1')));
    }

    #[Test]
    public function a_token_issued_beyond_the_allowed_skew_is_refused(): void
    {
        $clock = new FakeClock;
        $receiver = $this->pinnedReceiver($clock);
        $now = $clock->now();

        $this->expectRefusal(SignalErrorCode::IssuedInFuture, fn () => $receiver->receive($this->connection(), $this->token(issuedAt: $now->add(new DateInterval('PT61S')), jti: 'early')));

        $outcome = $receiver->receive($this->connection(), $this->token(issuedAt: $now->add(new DateInterval('PT60S')), jti: 'on-time'));

        Assert::assertTrue($outcome->jti->equals(new SignalId('on-time')));
    }

    #[Test]
    public function an_expired_token_is_refused_beyond_the_allowed_skew(): void
    {
        $clock = new FakeClock;
        $receiver = $this->pinnedReceiver($clock);
        $now = $clock->now();
        $issued = $now->sub(new DateInterval('PT10M'));

        $this->expectRefusal(SignalErrorCode::Expired, fn () => $receiver->receive($this->connection(), $this->token(issuedAt: $issued, expiresAt: $now->sub(new DateInterval('PT60S')), jti: 'late')));

        $outcome = $receiver->receive($this->connection(), $this->token(issuedAt: $issued, expiresAt: $now->sub(new DateInterval('PT59S')), jti: 'in-time'));

        Assert::assertTrue($outcome->jti->equals(new SignalId('in-time')));
    }

    #[Test]
    public function a_token_without_the_logout_event_is_refused(): void
    {
        $receiver = $this->pinnedReceiver(new FakeClock);

        $this->expectRefusal(SignalErrorCode::LogoutEventMissing, fn () => $receiver->receive($this->connection(), $this->token(events: [])));
        $this->expectRefusal(SignalErrorCode::LogoutEventMissing, fn () => $receiver->receive($this->connection(), $this->token(events: [new EventTypeUri('https://schemas.openid.net/secevent/caep/event-type/session-revoked')])));

        $outcome = $receiver->receive($this->connection(), $this->token(events: [new EventTypeUri('https://example.org/event/other'), EventTypeUri::backChannelLogout()]));

        Assert::assertSame(LogoutScope::IdpSession, $outcome->scope);
    }

    #[Test]
    public function a_token_with_a_nonce_is_refused(): void
    {
        $receiver = $this->pinnedReceiver(new FakeClock);

        $this->expectRefusal(SignalErrorCode::NoncePresent, fn () => $receiver->receive($this->connection(), $this->token(nonce: true)));
    }

    #[Test]
    public function a_token_without_a_subject_or_a_session_is_refused(): void
    {
        $receiver = $this->pinnedReceiver(new FakeClock);

        $this->expectRefusal(SignalErrorCode::SubjectMissing, fn () => $receiver->receive($this->connection(), $this->token(subject: null, session: null)));
    }

    #[Test]
    public function the_checks_run_in_order(): void
    {
        $clock = new FakeClock;
        $receiver = $this->pinnedReceiver($clock);
        $broken = fn (bool $issuer, bool $audience, bool $future, bool $expired, bool $events, bool $nonce): LogoutToken => $this->token(
            issuer: $issuer ? new Issuer('https://example.okta.com') : $this->issuer(),
            audience: [$audience ? new Audience('another-client') : $this->audience()],
            issuedAt: $future ? $clock->now()->add(new DateInterval('PT1H')) : ($expired ? $clock->now()->sub(new DateInterval('PT2H')) : null),
            expiresAt: $expired ? $clock->now()->sub(new DateInterval('PT1H')) : ($future ? $clock->now()->add(new DateInterval('PT2H')) : null),
            events: $events ? [] : [EventTypeUri::backChannelLogout()],
            nonce: $nonce,
            subject: null,
            session: null,
        );

        $this->expectRefusal(SignalErrorCode::IssuerMismatch, fn () => $receiver->receive($this->connection(), $broken(true, true, true, false, true, true)));
        $this->expectRefusal(SignalErrorCode::AudienceMismatch, fn () => $receiver->receive($this->connection(), $broken(false, true, true, false, true, true)));
        $this->expectRefusal(SignalErrorCode::IssuedInFuture, fn () => $receiver->receive($this->connection(), $broken(false, false, true, false, true, true)));
        $this->expectRefusal(SignalErrorCode::Expired, fn () => $receiver->receive($this->connection(), $broken(false, false, false, true, true, true)));
        $this->expectRefusal(SignalErrorCode::LogoutEventMissing, fn () => $receiver->receive($this->connection(), $broken(false, false, false, false, true, true)));
        $this->expectRefusal(SignalErrorCode::NoncePresent, fn () => $receiver->receive($this->connection(), $broken(false, false, false, false, false, true)));
        $this->expectRefusal(SignalErrorCode::SubjectMissing, fn () => $receiver->receive($this->connection(), $broken(false, false, false, false, false, false)));
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

        Assert::fail('A logout for an unknown connection was received; it must throw UnknownConnection.');
    }

    private function pinnedReceiver(FakeClock $clock, SignalPin ...$more): BackChannelLogoutReceiver
    {
        return $this->logouts()->receiver($clock, new SignalPin($this->connection(), $this->issuer(), $this->audience()), ...$more);
    }

    private function connection(): ConnectionId
    {
        return new ConnectionId('cbox-id');
    }

    private function issuer(): Issuer
    {
        return new Issuer('https://id.example.org');
    }

    private function audience(): Audience
    {
        return new Audience('cms-client');
    }

    private function subject(): Subject
    {
        return new Subject('248289761001');
    }

    private function session(): IdpSessionId
    {
        return new IdpSessionId('08a5019c-17e1-4977-8f42-65a12843ea02');
    }

    /**
     * A logout token of the pinned issuer for the pinned audience, issued a minute before the fake
     * clock's start and valid for two minutes after it, unless an argument says otherwise.
     *
     * @param  list<Audience>|null  $audience
     * @param  list<EventTypeUri>|null  $events
     */
    private function token(
        ?Issuer $issuer = null,
        ?array $audience = null,
        ?DateTimeImmutable $issuedAt = null,
        ?DateTimeImmutable $expiresAt = null,
        string $jti = 'logout-1',
        ?array $events = null,
        ?Subject $subject = new Subject('248289761001'),
        ?IdpSessionId $session = new IdpSessionId('08a5019c-17e1-4977-8f42-65a12843ea02'),
        bool $nonce = false,
    ): LogoutToken {
        $start = new DateTimeImmutable(FakeClock::START);

        return new LogoutToken(
            $issuer ?? $this->issuer(),
            $audience ?? [$this->audience()],
            $issuedAt ?? $start->sub(new DateInterval('PT1M')),
            $expiresAt ?? $start->add(new DateInterval('PT2M')),
            new SignalId($jti),
            $events ?? [EventTypeUri::backChannelLogout()],
            $subject,
            $session,
            $nonce,
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

        Assert::fail(sprintf('The logout was received; it must be refused with %s.', $reason->value));
    }
}
