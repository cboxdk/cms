<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\LoginConnection;
use Cbox\Cms\Contracts\Identity\Login\LoginResponse;
use Cbox\Cms\Contracts\Identity\Login\LoginStarted;

/**
 * What the shared suite LoginConnectionContract needs besides the connection: the identity
 * provider's side of it.
 *
 * - connection() is the connection under test.
 * - issuer() is the issuer the connection is pinned to.
 * - accepted() is what comes back for a started login when a person the provider knows logs in,
 *   with what the assertion must say. For the flow Direct it is the credentials the form takes; for
 *   the flow Redirect it is the callback the provider sends the browser to. A harness for a real
 *   connection drives a provider that stands in for the real one.
 * - refused() is what comes back for a started login that the provider or the check does not
 *   accept: a wrong secret, or a callback with an error.
 */
#[Experimental]
interface LoginConnectionHarness
{
    public function connection(): LoginConnection;

    public function issuer(): Issuer;

    public function accepted(LoginStarted $started): AcceptedLogin;

    public function refused(LoginStarted $started): LoginResponse;
}
