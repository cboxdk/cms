<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A login connection (PRD 5.16, "Identitetskontrakten"): it verifies who logged in, when and with
 * which factors, and gives a VerifiedAssertion. The kernel owns everything after that: the actor,
 * its state, the login policy, grants and revocation. A connection decides nothing of those.
 *
 * Both flows have the same two steps:
 *
 * 1. start() makes a PendingLogin with a fresh, unguessable state. For the flow Direct, the login
 *    form carries the state and takes the credentials; for the flow Redirect, the browser is sent to
 *    LoginStarted::$redirectTo, which carries the state.
 * 2. complete() takes the pending login back with the response, SubmittedCredentials or
 *    CallbackParameters. It first checks that the response belongs to the pending login
 *    (PendingLogin::check(), login_state_mismatch), then the credentials or the token
 *    (login_rejected), and for a token the issuer and tenant it is pinned to, through its
 *    IssuerResolver (IssuerPin::admit(): login_issuer_mismatch, login_tenant_claim_missing,
 *    login_tenant_mismatch).
 *
 * A refused login says why only to the log and the audit; the person is told the login failed.
 */
#[Experimental]
interface LoginConnection
{
    public function id(): ConnectionId;

    public function flow(): LoginFlow;

    /**
     * Starts a login. Every call gives a pending login with a state of its own.
     */
    public function start(): LoginStarted;

    /**
     * Completes a pending login of this connection with the response that came back for it.
     *
     * @throws LoginRefused
     */
    public function complete(PendingLogin $pending, LoginResponse $response): VerifiedAssertion;
}
