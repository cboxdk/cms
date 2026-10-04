<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests;

use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordHasher;
use Cbox\Cms\Identity\Sessions\Domain\Dto\SessionCookie;
use Cbox\Cms\Panel\Boundary\LoginForm;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Illuminate\Testing\TestResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signing in to the panel on real Postgres and Valkey in a test case of the Postgres suite, as a
 * browser does (PRD 5.16): the actor gets a local account with the password, the login page's
 * visit starts Laravel's session, whose CSRF token the form posts with, and the login's answer
 * sets the CMS session cookie, which every request behind the login carries unencrypted, as the
 * panel leaves it out of Laravel's cookie encryption.
 */
trait PanelSignIn
{
    public const string PASSWORD = 'correct horse battery staple';

    /**
     * Gives the actor a local account with the email and PASSWORD.
     */
    protected function giveLocalAccount(ActorId $actor, string $email): void
    {
        app(LocalCredentialStore::class)->bind($actor, new LoginIdentifier($email), app(PasswordHasher::class)->hash(new Password(self::PASSWORD)));
    }

    /**
     * Signs the account in through the panel's login form and gives the CMS session cookie's value.
     */
    protected function signInAs(string $email): string
    {
        $this->get('/cms/login')->assertOk();
        $response = $this->from('/cms/login')->post('/cms/login', [
            LoginForm::EMAIL => $email,
            LoginForm::PASSWORD => self::PASSWORD,
            '_token' => app('session.store')->token(),
        ]);
        $response->assertRedirect('/cms');
        $cookie = $response->getCookie($this->sessionCookieName(), false);

        return $cookie instanceof Cookie ? (string) $cookie->getValue() : throw new LogicException('The login set no session cookie.');
    }

    /**
     * A GET of a panel page as an Inertia visit with the session and the build's version, or a
     * partial reload of the props given, as the host asks for deferred props.
     *
     * @param  list<string>  $only
     * @return TestResponse<Response>
     */
    protected function visitPanel(string $session, string $path, string $component = '', array $only = []): TestResponse
    {
        return $this->withUnencryptedCookie($this->sessionCookieName(), $session)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => app(PanelBuild::class)->version,
                ...($only === [] ? [] : ['X-Inertia-Partial-Component' => $component, 'X-Inertia-Partial-Data' => implode(',', $only)]),
            ])
            ->get($path);
    }

    protected function sessionCookieName(): string
    {
        return app(SessionCookie::class)->name;
    }
}
