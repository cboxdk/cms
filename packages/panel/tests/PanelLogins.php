<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests;

use Cbox\Cms\Identity\Sessions\Domain\Dto\SessionCookie;
use Cbox\Cms\Identity\Tests\Login\LocalLoginWorld;
use Cbox\Cms\Panel\Boundary\LoginForm;
use Illuminate\Contracts\Session\Session;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logging in to the panel in the workbench, which mounts it at /cms, over LocalLoginWorld's fakes
 * and a FixtureBuild: the login page's visit starts Laravel's session, whose CSRF token csrf()
 * reads, and logIn() posts the login form as a browser does. The session cookie travels unencrypted,
 * as the panel leaves it out of Laravel's cookie encryption.
 */
trait PanelLogins
{
    protected ?FixtureBuild $fixture = null;

    protected ?LocalLoginWorld $logins = null;

    protected function setUpPanelLogins(): LocalLoginWorld
    {
        $app = $this->app ?? app();
        $this->fixture = FixtureBuild::write();
        $this->fixture->bind($app);
        $this->logins = new LocalLoginWorld;
        $this->logins->into($app);

        return $this->logins;
    }

    protected function tearDownPanelLogins(): void
    {
        $this->fixture?->remove();
        $this->fixture = null;
        $this->logins = null;
    }

    /**
     * Visits the login page, which starts Laravel's session, and returns the response.
     *
     * @return TestResponse<Response>
     */
    protected function visitLogin(): TestResponse
    {
        return $this->get('/cms/login')->assertOk();
    }

    /**
     * The CSRF token of Laravel's session as the server holds it now.
     */
    protected function csrf(): string
    {
        return $this->laravelSession()->token();
    }

    protected function laravelSession(): Session
    {
        return app('session.store');
    }

    /**
     * Posts the login form with the CSRF token, and the session cookie when one is given.
     *
     * @return TestResponse<Response>
     */
    protected function logIn(string $email, string $password, ?string $session = null): TestResponse
    {
        if ($session !== null) {
            $this->withUnencryptedCookie($this->cookieName(), $session);
        }

        return $this->from('/cms/login')->post('/cms/login', [
            LoginForm::EMAIL => $email,
            LoginForm::PASSWORD => $password,
            '_token' => $this->csrf(),
        ]);
    }

    /**
     * The session cookie the response set, or null when it set none.
     *
     * @param  TestResponse<Response>  $response
     */
    protected function sessionCookie(TestResponse $response): ?Cookie
    {
        $cookie = $response->getCookie($this->cookieName(), false);

        return $cookie instanceof Cookie ? $cookie : null;
    }

    protected function cookieName(): string
    {
        return app(SessionCookie::class)->name;
    }
}
