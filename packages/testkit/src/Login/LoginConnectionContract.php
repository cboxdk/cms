<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Login\AuthenticationMethod;
use Cbox\Cms\Contracts\Identity\Login\CallbackParameters;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\IdpGroup;
use Cbox\Cms\Contracts\Identity\Login\IdpIdentity;
use Cbox\Cms\Contracts\Identity\Login\LoginConnection;
use Cbox\Cms\Contracts\Identity\Login\LoginErrorCode;
use Cbox\Cms\Contracts\Identity\Login\LoginFlow;
use Cbox\Cms\Contracts\Identity\Login\LoginRefused;
use Cbox\Cms\Contracts\Identity\Login\LoginResponse;
use Cbox\Cms\Contracts\Identity\Login\LoginState;
use Cbox\Cms\Contracts\Identity\Login\PendingLogin;
use Cbox\Cms\Contracts\Identity\Login\SubmittedCredentials;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared contract suite for LoginConnection (GUARDRAILS 2.3 and 9, PRD 5.16). The fake and
 * every real connection, of either flow, run the same cases.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return a
 * harness for a new connection whose identity provider knows the person accepted() logs in as. For
 * the testkit's fake of the flow Direct:
 *
 *     final class DirectFakeLoginConnectionContractTest extends TestCase
 *     {
 *         use LoginConnectionContract;
 *
 *         protected function login(): LoginConnectionHarness
 *         {
 *             $local = new ConnectionId('local');
 *             $issuer = new Issuer('https://cms.example.org');
 *             $issuers = new FakeIssuerResolver(new IssuerPin($local, $issuer));
 *
 *             return (new FakeLoginConnection($local, LoginFlow::Direct, $issuers))
 *                 ->enrol(new FakeLoginAccount('ada@example.org', 'correct horse battery',
 *                     new TokenClaims($issuer, new Subject('ada'))));
 *         }
 *     }
 *
 * The cases cover a start in the connection's flow with a state of its own, an accepted login and
 * the assertion it gives, a login the provider refuses, and every response that does not belong to
 * the pending login: one for another start, one with another or no state, one of the other flow,
 * and a pending login of another connection. Those are refused with login_state_mismatch before
 * the credentials or the token are looked at. The issuer and tenant checks of a token are
 * IssuerResolverContract's.
 */
#[Experimental]
trait LoginConnectionContract
{
    /**
     * A harness for a new connection whose identity provider knows the person accepted() logs in as.
     */
    abstract protected function login(): LoginConnectionHarness;

    #[Test]
    public function a_login_starts_in_the_connections_flow(): void
    {
        $connection = $this->login()->connection();

        $started = $connection->start();

        Assert::assertTrue($started->pending->connection->equals($connection->id()));
        Assert::assertSame($connection->flow(), $started->pending->flow);
        Assert::assertSame($connection->flow() === LoginFlow::Redirect, $started->redirectTo !== null);
    }

    #[Test]
    public function a_redirect_carries_the_state_of_its_pending_login(): void
    {
        $connection = $this->login()->connection();
        $started = $connection->start();

        if ($connection->flow() === LoginFlow::Direct) {
            Assert::assertNull($started->redirectTo);

            return;
        }

        Assert::assertNotNull($started->redirectTo);
        parse_str((string) parse_url($started->redirectTo, PHP_URL_QUERY), $query);
        Assert::assertSame($started->pending->state->value, $query['state'] ?? null);
    }

    #[Test]
    public function every_start_has_a_state_of_its_own(): void
    {
        $connection = $this->login()->connection();

        $states = array_map(static fn (): string => $connection->start()->pending->state->value, range(1, 5));

        Assert::assertSame($states, array_values(array_unique($states)));
    }

    #[Test]
    public function an_accepted_login_gives_the_verified_assertion_of_the_person(): void
    {
        $harness = $this->login();
        $connection = $harness->connection();
        $started = $connection->start();
        $accepted = $harness->accepted($started);

        $assertion = $connection->complete($started->pending, $accepted->response);

        Assert::assertTrue($assertion->identity()->equals(new IdpIdentity($connection->id(), $harness->issuer(), $accepted->subject)));
        Assert::assertSame('UTC', $assertion->authTime->getTimezone()->getName());
        Assert::assertSame($this->methodNames($accepted->amr), $this->methodNames($assertion->amr));
        Assert::assertSame($accepted->acr?->value, $assertion->acr?->value);
        Assert::assertSame($this->groupNames($accepted->groups), $this->groupNames($assertion->groups));
    }

    #[Test]
    public function a_login_the_provider_refuses_is_rejected(): void
    {
        $harness = $this->login();
        $connection = $harness->connection();
        $started = $connection->start();

        $this->expectRefusal($connection, LoginErrorCode::Rejected, $started->pending, $harness->refused($started));
    }

    #[Test]
    public function a_response_for_another_start_is_refused(): void
    {
        $harness = $this->login();
        $connection = $harness->connection();
        $mine = $connection->start();
        $theirs = $connection->start();

        $this->expectRefusal($connection, LoginErrorCode::StateMismatch, $mine->pending, $harness->accepted($theirs)->response);
    }

    #[Test]
    public function a_response_with_another_state_or_none_is_refused(): void
    {
        $harness = $this->login();
        $connection = $harness->connection();
        $started = $connection->start();
        $other = LoginState::fromRandomBytes(random_bytes(32))->value;

        foreach ([$other, '', $started->pending->state->value.'x'] as $state) {
            $response = $connection->flow() === LoginFlow::Direct
                ? new SubmittedCredentials($state, 'someone', 'a secret')
                : new CallbackParameters(['code' => 'a code', 'state' => $state]);

            $this->expectRefusal($connection, LoginErrorCode::StateMismatch, $started->pending, $response);
        }

        if ($connection->flow() === LoginFlow::Redirect) {
            $this->expectRefusal($connection, LoginErrorCode::StateMismatch, $started->pending, new CallbackParameters(['code' => 'a code']));
        }
    }

    #[Test]
    public function a_response_of_the_other_flow_is_refused(): void
    {
        $harness = $this->login();
        $connection = $harness->connection();
        $started = $connection->start();
        $state = $started->pending->state->value;

        $response = $connection->flow() === LoginFlow::Direct
            ? new CallbackParameters(['code' => 'a code', 'state' => $state])
            : new SubmittedCredentials($state, 'someone', 'a secret');

        $this->expectRefusal($connection, LoginErrorCode::StateMismatch, $started->pending, $response);
    }

    #[Test]
    public function a_pending_login_of_another_connection_is_refused(): void
    {
        $harness = $this->login();
        $connection = $harness->connection();
        $started = $connection->start();
        $other = new ConnectionId($connection->id()->value === 'elsewhere' ? 'another' : 'elsewhere');
        $foreign = new PendingLogin($other, $started->pending->flow, $started->pending->state, $started->pending->secrets());

        $this->expectRefusal($connection, LoginErrorCode::StateMismatch, $foreign, $harness->accepted($started)->response);
    }

    private function expectRefusal(LoginConnection $connection, LoginErrorCode $reason, PendingLogin $pending, LoginResponse $response): void
    {
        try {
            $assertion = $connection->complete($pending, $response);
        } catch (LoginRefused $refused) {
            Assert::assertSame($reason, $refused->reason);

            return;
        }

        Assert::fail(sprintf(
            'The login was completed as %s; it must be refused with %s.',
            $assertion->subject->value,
            $reason->value,
        ));
    }

    /**
     * @param  list<AuthenticationMethod>  $methods
     * @return list<string>
     */
    private function methodNames(array $methods): array
    {
        return array_map(static fn (AuthenticationMethod $method): string => $method->value, $methods);
    }

    /**
     * @param  list<IdpGroup>|null  $groups
     * @return list<string>|null
     */
    private function groupNames(?array $groups): ?array
    {
        return $groups === null ? null : array_map(static fn (IdpGroup $group): string => $group->value, $groups);
    }
}
