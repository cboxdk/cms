<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\Login\CallbackParameters;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\IssuerResolver;
use Cbox\Cms\Contracts\Identity\Login\LoginConnection;
use Cbox\Cms\Contracts\Identity\Login\LoginErrorCode;
use Cbox\Cms\Contracts\Identity\Login\LoginFlow;
use Cbox\Cms\Contracts\Identity\Login\LoginRefused;
use Cbox\Cms\Contracts\Identity\Login\LoginResponse;
use Cbox\Cms\Contracts\Identity\Login\LoginStarted;
use Cbox\Cms\Contracts\Identity\Login\LoginState;
use Cbox\Cms\Contracts\Identity\Login\PendingLogin;
use Cbox\Cms\Contracts\Identity\Login\SubmittedCredentials;
use Cbox\Cms\Contracts\Identity\Login\VerifiedAssertion;
use Cbox\Cms\Testkit\Clock\FakeClock;
use LogicException;
use Override;

/**
 * The in-memory fake of LoginConnection (GUARDRAILS 2.3), and its own harness: a connection of
 * either flow with an identity provider that knows the accounts enrol() gives it. The real
 * connections, local accounts and OpenID Connect, come with the identity module.
 *
 * For the flow Direct, complete() takes the credentials of an account. For the flow Redirect,
 * start() makes a nonce, respondAs() is the provider's callback with a code issued for the account
 * and that nonce, and complete() takes the code once. Either way it checks the response against the
 * pending login first (PendingLogin::check()), then the credentials or the code, then admits the
 * account's token claims through its IssuerResolver, and gives the assertion with the Clock's time
 * as auth_time.
 */
#[Experimental]
final class FakeLoginConnection implements LoginConnection, LoginConnectionHarness
{
    private const string NONCE = 'nonce';

    /** @var list<FakeLoginAccount> */
    private array $accounts = [];

    /** @var array<string, FakeLoginAccount> by code, the account it was issued for */
    private array $codeAccounts = [];

    /** @var array<string, string> by code, the nonce it was issued for */
    private array $codeNonces = [];

    public function __construct(
        private readonly ConnectionId $id,
        private readonly LoginFlow $flow,
        private readonly IssuerResolver $issuers,
        private readonly Clock $clock = new FakeClock,
    ) {}

    /**
     * Makes the provider know the account. The first account is the one accepted() logs in as.
     */
    public function enrol(FakeLoginAccount $account): self
    {
        $this->accounts[] = $account;

        return $this;
    }

    #[Override]
    public function id(): ConnectionId
    {
        return $this->id;
    }

    #[Override]
    public function flow(): LoginFlow
    {
        return $this->flow;
    }

    #[Override]
    public function start(): LoginStarted
    {
        $state = LoginState::fromRandomBytes(random_bytes(32));

        if ($this->flow === LoginFlow::Direct) {
            return new LoginStarted(new PendingLogin($this->id, $this->flow, $state));
        }

        $nonce = bin2hex(random_bytes(16));

        return new LoginStarted(
            new PendingLogin($this->id, $this->flow, $state, [self::NONCE => $nonce]),
            $this->issuer()->value.'/authorize?'.http_build_query([
                'client_id' => $this->id->value,
                'nonce' => $nonce,
                'response_type' => 'code',
                'state' => $state->value,
            ]),
        );
    }

    #[Override]
    public function complete(PendingLogin $pending, LoginResponse $response): VerifiedAssertion
    {
        $pending->check($this->id, $response);

        $account = $response instanceof SubmittedCredentials
            ? $this->checkCredentials($response)
            : $this->redeem($pending, $response);

        return VerifiedAssertion::of(
            $this->issuers->admit($this->id, $account->claims),
            $this->clock->now(),
            $account->amr,
            $account->acr,
            $account->groups,
        );
    }

    #[Override]
    public function connection(): LoginConnection
    {
        return $this;
    }

    #[Override]
    public function issuer(): Issuer
    {
        return $this->issuers->pin($this->id)->issuer;
    }

    #[Override]
    public function accepted(LoginStarted $started): AcceptedLogin
    {
        $account = $this->accounts[0] ?? throw new LogicException('The fake login connection has no account; enrol() one first.');

        return new AcceptedLogin($this->respondAs($started, $account), $account->claims->subject, $account->amr, $account->acr, $account->groups);
    }

    #[Override]
    public function refused(LoginStarted $started): LoginResponse
    {
        $state = $started->pending->state->value;

        return $this->flow === LoginFlow::Direct
            ? new SubmittedCredentials($state, $this->accounts[0]->identifier ?? 'nobody', ($this->accounts[0]->secret ?? '').' but wrong')
            : new CallbackParameters(['error' => 'access_denied', 'state' => $state]);
    }

    /**
     * What comes back when the account logs in for the started login: its credentials for the flow
     * Direct, or the provider's callback with a code issued for it for the flow Redirect. The
     * account need not be enrolled, so a test can log in with a token of another issuer or tenant.
     */
    public function respondAs(LoginStarted $started, FakeLoginAccount $account): LoginResponse
    {
        $pending = $started->pending;

        if ($this->flow === LoginFlow::Direct) {
            return new SubmittedCredentials($pending->state->value, $account->identifier, $account->secret);
        }

        $code = bin2hex(random_bytes(16));
        $this->codeAccounts[$code] = $account;
        $this->codeNonces[$code] = $pending->secret(self::NONCE);

        return new CallbackParameters(['code' => $code, 'state' => $pending->state->value]);
    }

    /**
     * @throws LoginRefused
     */
    private function checkCredentials(SubmittedCredentials $credentials): FakeLoginAccount
    {
        foreach ($this->accounts as $account) {
            if ($account->identifier === $credentials->identifier && hash_equals($account->secret, $credentials->secret())) {
                return $account;
            }
        }

        throw LoginRefused::because(LoginErrorCode::Rejected);
    }

    /**
     * @throws LoginRefused
     */
    private function redeem(PendingLogin $pending, LoginResponse $response): FakeLoginAccount
    {
        $code = $response instanceof CallbackParameters ? $response->parameter('code') : null;

        if ($code === null || ! isset($this->codeAccounts[$code], $this->codeNonces[$code])) {
            throw LoginRefused::because(LoginErrorCode::Rejected);
        }

        $account = $this->codeAccounts[$code];
        $nonce = $this->codeNonces[$code];
        unset($this->codeAccounts[$code], $this->codeNonces[$code]);

        if (! hash_equals($nonce, $pending->secret(self::NONCE))) {
            throw LoginRefused::because(LoginErrorCode::Rejected);
        }

        return $account;
    }
}
