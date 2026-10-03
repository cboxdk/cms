<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Feature;

use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\SessionToken;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Identity\Login\Boundary\LoginInput;
use Cbox\Cms\Identity\Login\Domain\ClientAddress;
use Cbox\Cms\Identity\Login\Domain\Dto\LocalLoginRequest;
use Cbox\Cms\Identity\Sessions\Boundary\SessionCookieConfig;
use Cbox\Cms\Identity\Sessions\Domain\Dto\SessionCookie;
use Cbox\Cms\Identity\Sessions\Domain\SessionKey;
use Cbox\Cms\Identity\Tests\Login\LocalLoginWorld;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\Boundary\PanelSessions;
use Cbox\Cms\Panel\Tests\PanelLogins;
use Cbox\Cms\Tests\TestCase;
use DateInterval;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * The panel's two cookies (PRD 5.16, docs/security/sessions.md): the CMS session cookie, the only
 * credential, and Laravel's session cookie, which carries Inertia's flash data, the errors prop and
 * the CSRF token. A login gives both new ids, whatever the browser carried, and binds Laravel's
 * session to the CMS session; a Laravel session bound to another one is emptied; a logout ends both
 * and clears both cookies; and the CMS cookie has the attributes of the environment's entry of
 * cbox-cms.identity.session.cookie.
 */
final class SessionCookieTest extends TestCase
{
    use PanelLogins;

    private const string EMAIL = 'mette.holm@example.com';

    private ?Actor $person = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->person = $this->setUpPanelLogins()->person(self::EMAIL);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tearDownPanelLogins();

        parent::tearDown();
    }

    #[Test]
    public function a_login_gives_both_cookies_new_ids_and_binds_laravels_session_to_the_cms_session(): void
    {
        $page = $this->visitLogin();
        $laravelBefore = $this->laravelSessionId($page);
        $tokenBefore = $this->csrf();
        $planted = $this->logins()->action()->login(new LocalLoginRequest(LoginInput::login(self::EMAIL), LoginInput::password($this->password()), new ClientAddress('192.0.2.99')))->session;
        self::assertNotNull($planted);

        $response = $this->logIn(self::EMAIL, $this->password(), $planted->token->credential()->reveal());

        $response->assertStatus(PanelSessions::REDIRECT)->assertHeader('Location', '/cms');

        $cookie = $this->sessionCookie($response) ?? self::fail('The login set no session cookie.');
        $issued = SessionToken::parse(TransportCredential::session((string) $cookie->getValue()));
        $laravelAfter = $this->laravelSessionId($response);

        self::assertNotSame($planted->token->hash(), $issued->hash());
        self::assertNull($this->logins()->sessions->find(SessionKey::of($planted->token)), 'The session the browser carried is ended.');
        self::assertNotNull($this->logins()->sessions->find(SessionKey::of($issued)));
        self::assertNotSame($laravelBefore, $laravelAfter);
        self::assertNotSame($tokenBefore, $this->csrf());
        self::assertSame($issued->hash(), $this->laravelSession()->get(PanelSessions::BINDING));
        self::assertSame($laravelAfter, $this->laravelSession()->getId());
    }

    #[Test]
    public function a_logout_ends_both_sessions_and_clears_both_cookies(): void
    {
        $this->visitLogin();
        $cookie = $this->sessionCookie($this->logIn(self::EMAIL, $this->password())) ?? self::fail('The login set no session cookie.');
        $token = SessionToken::parse(TransportCredential::session((string) $cookie->getValue()));
        $this->withUnencryptedCookie($this->cookieName(), (string) $cookie->getValue());
        $this->get('/cms')->assertOk();
        $laravelBefore = $this->laravelSession()->getId();
        $this->withCookie($this->laravelCookieName(), $laravelBefore);

        $response = $this->post('/cms/logout', ['_token' => $this->csrf()]);

        $response->assertStatus(PanelSessions::REDIRECT)
            ->assertHeader('Location', '/cms/login?reason=signed_out')
            ->assertCookieExpired($this->cookieName())
            ->assertCookieExpired($this->laravelCookieName());

        $laravel = $this->laravelSession();

        self::assertNull($this->logins()->sessions->find(SessionKey::of($token)));
        self::assertNotSame($laravelBefore, $laravel->getId());
        self::assertNull($laravel->get(PanelSessions::BINDING));
        self::assertNull($laravel->getHandler()->read($laravelBefore) ?: null);
        self::assertNull($laravel->getHandler()->read($laravel->getId()) ?: null, 'The empty session started after the logout is destroyed too.');

        $this->get('/cms')->assertStatus(PanelSessions::REDIRECT)->assertHeader('Location', '/cms/login?reason=ended');
    }

    #[Test]
    public function the_start_page_behind_the_login_is_never_stored_as_a_page_or_as_inertia_json(): void
    {
        $this->visitLogin();
        $cookie = $this->sessionCookie($this->logIn(self::EMAIL, $this->password())) ?? self::fail('The login set no session cookie.');
        $this->withUnencryptedCookie($this->cookieName(), (string) $cookie->getValue());

        $html = $this->get('/cms')->assertOk();
        $page = $html->viewData('page');
        $version = is_array($page) && is_string($page['version'] ?? null) ? $page['version'] : self::fail('The start page rendered no Inertia page.');
        $json = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->get('/cms')->assertOk();

        self::assertSame(PanelPages::PRIVATE_CACHE_CONTROL, $html->headers->get('Cache-Control'));
        self::assertSame(PanelPages::PRIVATE_CACHE_CONTROL, $json->headers->get('Cache-Control'));
        self::assertSame('true', $json->headers->get('X-Inertia'));
    }

    #[Test]
    public function a_laravel_session_bound_to_another_cms_session_is_emptied_before_it_is_bound(): void
    {
        $this->visitLogin();
        $cookie = $this->sessionCookie($this->logIn(self::EMAIL, $this->password())) ?? self::fail('The login set no session cookie.');
        $this->withUnencryptedCookie($this->cookieName(), (string) $cookie->getValue());
        $laravel = $this->laravelSession();
        $laravel->put(PanelSessions::BINDING, str_repeat('0', 64));
        $laravel->put('planted', 'by another browser');
        $before = $laravel->getId();
        $token = $this->csrf();

        $this->get('/cms')->assertOk();

        self::assertNull($laravel->get('planted'));
        self::assertNotSame($before, $laravel->getId());
        self::assertNotSame($token, $this->csrf());
        self::assertSame(SessionToken::parse(TransportCredential::session((string) $cookie->getValue()))->hash(), $laravel->get(PanelSessions::BINDING));
    }

    #[Test]
    public function the_session_cookie_has_the_attributes_of_the_testing_environment(): void
    {
        $this->visitLogin();
        $cookie = $this->sessionCookie($this->logIn(self::EMAIL, $this->password())) ?? self::fail('The login set no session cookie.');

        self::assertSame('cms_session', $cookie->getName());
        $this->assertSessionCookie($cookie, secure: false);
    }

    #[Test]
    public function the_session_cookie_has_the_attributes_of_the_production_entry(): void
    {
        $this->app?->instance(SessionCookie::class, SessionCookieConfig::read(app(Repository::class), 'production'));
        $this->visitLogin();
        $cookie = $this->sessionCookie($this->logIn(self::EMAIL, $this->password())) ?? self::fail('The login set no session cookie.');

        self::assertSame('__Host-cms_session', $cookie->getName());
        $this->assertSessionCookie($cookie, secure: true);
        self::assertStringStartsWith('__Host-cms_session=cms_ss_', (string) $cookie);
        self::assertStringContainsString('; secure; httponly; samesite=lax', (string) $cookie);
    }

    #[Test]
    public function a_request_without_a_session_or_with_one_the_verifier_refuses_goes_to_the_login_page_with_the_reason(): void
    {
        $this->get('/cms')->assertStatus(PanelSessions::REDIRECT)->assertHeader('Location', '/cms/login?reason=required');

        $this->visitLogin();
        $cookie = $this->sessionCookie($this->logIn(self::EMAIL, $this->password())) ?? self::fail('The login set no session cookie.');
        $this->withUnencryptedCookie($this->cookieName(), (string) $cookie->getValue());
        $this->logins()->identity->changeState(($this->person ?? self::fail('No person.'))->id, ActorState::Deactivated);

        $this->get('/cms')
            ->assertStatus(PanelSessions::REDIRECT)
            ->assertHeader('Location', '/cms/login?reason=revoked')
            ->assertCookieExpired($this->cookieName());

        // The store drops a session at its end, so a session past its lifetimes is no longer there.
        $this->logins()->identity->changeState(($this->person ?? self::fail('No person.'))->id, ActorState::Active);
        $this->visitLogin();
        $cookie = $this->sessionCookie($this->logIn(self::EMAIL, $this->password())) ?? self::fail('The login set no session cookie.');
        $this->withUnencryptedCookie($this->cookieName(), (string) $cookie->getValue());
        $this->logins()->clock->advance(new DateInterval('PT2H'));

        $this->get('/cms')->assertStatus(PanelSessions::REDIRECT)->assertHeader('Location', '/cms/login?reason=ended');

        $this->withUnencryptedCookie($this->cookieName(), 'cms_ss_not-a-session');
        $this->get('/cms')->assertStatus(PanelSessions::REDIRECT)->assertHeader('Location', '/cms/login?reason=ended');
    }

    private function assertSessionCookie(Cookie $cookie, bool $secure): void
    {
        self::assertSame($secure, $cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertSame('/', $cookie->getPath());
        self::assertNull($cookie->getDomain());
        self::assertSame(0, $cookie->getExpiresTime(), 'The cookie lives as long as the browser; the store holds the lifetimes.');
        self::assertMatchesRegularExpression('/\Acms_ss_[0-9a-f]{72}\z/', (string) $cookie->getValue());
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function laravelSessionId(TestResponse $response): string
    {
        $cookie = $response->getCookie($this->laravelCookieName());

        return $cookie instanceof Cookie ? (string) $cookie->getValue() : self::fail('The response set no Laravel session cookie.');
    }

    private function laravelCookieName(): string
    {
        $name = app(Repository::class)->get('session.cookie');

        return is_string($name) ? $name : self::fail('There is no Laravel session cookie name.');
    }

    private function password(): string
    {
        return LocalLoginWorld::PASSWORD;
    }

    private function logins(): LocalLoginWorld
    {
        return $this->logins ?? self::fail('The test has no logins.');
    }
}
