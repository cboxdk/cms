<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\CredentialErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\SessionToken;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Http\Credentials\Boundary\RequestCredential;
use Cbox\Cms\Identity\Sessions\Domain\Dto\NewSession;
use Cbox\Cms\Identity\Sessions\Domain\Dto\SessionCookie;
use Cbox\Cms\Panel\Domain\PanelRoute;
use Cbox\Cms\Panel\Domain\SignInReason;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Support\Header;
use LogicException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The panel's two sessions (PRD 5.16), and how they relate:
 *
 * - the CMS session, the only credential of a person in the panel: its id in the session cookie of
 *   the environment (SessionCookie), always HttpOnly, with path / and no Domain, and without an
 *   expiry, because the session store holds its lifetimes and the cookie lives only as long as the
 *   browser;
 * - Laravel's session, with its own cookie, which carries only Inertia's flash data, the errors
 *   prop and the CSRF token, and BINDING, the SHA-256 of the CMS session's id (never the id) that
 *   binds it to the CMS session it was started with.
 *
 * At a login (start()) both get new ids: the CMS session is a new one from IssueSession, and
 * Laravel's session is emptied, given a new id and a new CSRF token, and bound to it. At every
 * other request (authenticate()) the CMS session is verified through the CredentialVerifier, which
 * renews it; a Laravel session bound to another CMS session, or to none, is emptied and given a new
 * id and token before it is bound, so a Laravel session planted in the browser never carries a CSRF
 * token into a person's session. At a logout (ended()) both are ended and both cookies are cleared:
 * the CMS cookie on the response, and Laravel's, which its own middleware sets after the panel has
 * answered, by clearCookies() once the kernel has handled the request.
 */
#[Internal]
final readonly class PanelSessions
{
    /** The key of Laravel's session that binds it to the CMS session. */
    public const string BINDING = 'cbox-cms.panel.session';

    /** The request attribute that holds the authenticated request's SessionToken. */
    public const string TOKEN = 'cbox-cms.panel.session-token';

    /** The request attribute that holds the authenticated request's ActorPrincipal. */
    public const string PRINCIPAL = 'cbox-cms.panel.principal';

    /** The request attribute that marks a logout, whose Laravel session cookie clearCookies() clears. */
    public const string ENDED = 'cbox-cms.panel.session-ended';

    /** The status of every redirect: the browser follows it with a GET. */
    public const int REDIRECT = 303;

    /** The status of a redirect to an Inertia visit, which makes Inertia load the address as a new document. */
    public const int FULL_PAGE = 409;

    public function __construct(
        private SessionCookie $cookie,
        private CredentialVerifier $verifier,
        private UrlGenerator $urls,
        private Repository $config,
    ) {}

    /**
     * Authenticates a panel request from its session cookie. Returns null when the session
     * verifies, after it has bound Laravel's session to it and put the session's token, principal
     * and credential on the request (TOKEN, PRINCIPAL, RequestCredential). Otherwise returns the
     * redirect to the login page with the reason: without a cookie the browser keeps everything;
     * with a session the verifier refused, the cookie is cleared and Laravel's session emptied.
     */
    public function authenticate(Request $request): ?Response
    {
        $value = $this->carried($request);

        if (! $value instanceof TransportCredential) {
            return $this->toLogin($request, SignInReason::Required);
        }

        try {
            $principal = $this->verifier->verify($value);
            $token = SessionToken::parse($value);
        } catch (CredentialRejected $rejected) {
            return $this->signedOut($request, SignInReason::refused($rejected->reason));
        }

        if (! $principal instanceof ActorPrincipal || $principal->issuerKind !== IssuerKind::Human) {
            return $this->signedOut($request, SignInReason::refused(CredentialErrorCode::Unknown));
        }

        $this->bind($this->laravel($request), $token);
        $request->attributes->set(self::TOKEN, $token);
        $request->attributes->set(self::PRINCIPAL, $principal);
        RequestCredential::authenticated($request, $value);

        return null;
    }

    /**
     * The redirect to the panel's start for a request to the login page that carries a session
     * that verifies, or null when it carries none that does.
     */
    public function signedIn(Request $request): ?Response
    {
        $value = $this->carried($request);

        if (! $value instanceof TransportCredential) {
            return null;
        }

        try {
            $principal = $this->verifier->verify($value);
        } catch (CredentialRejected) {
            return null;
        }

        return $principal instanceof ActorPrincipal && $principal->issuerKind === IssuerKind::Human
            ? $this->redirect($request, $this->urls->route(PanelRoute::Home->value, [], false))
            : null;
    }

    /**
     * The session credential the request's cookie carries, or null when it carries none.
     */
    public function carried(Request $request): ?TransportCredential
    {
        $value = $request->cookies->get($this->cookie->name);

        return is_string($value) && $value !== '' ? TransportCredential::session($value) : null;
    }

    /**
     * After a login: empties Laravel's session, gives it a new id and CSRF token, binds it to the
     * new CMS session, and redirects to the panel's start with the new session cookie.
     */
    public function start(Request $request, NewSession $session): Response
    {
        $laravel = $this->laravel($request);
        $laravel->invalidate();
        $laravel->regenerateToken();
        $laravel->put(self::BINDING, $session->token->hash());

        $response = $this->redirect($request, $this->urls->route(PanelRoute::Home->value, [], false));
        $response->headers->setCookie($this->sessionCookie($session->token->credential()->reveal()));

        return $response;
    }

    /**
     * The token of the session the request was authenticated with.
     *
     * @throws LogicException when the request did not pass AuthenticatePanelSession
     */
    public function token(Request $request): SessionToken
    {
        $token = $request->attributes->get(self::TOKEN);

        return $token instanceof SessionToken ? $token : throw new LogicException('The panel request was not authenticated: register its route behind AuthenticatePanelSession.');
    }

    /**
     * After a logout, whose CMS session the caller has ended: ends Laravel's session too, marks
     * the request so clearCookies() clears its cookie, and redirects to the login page with the
     * session cookie cleared.
     */
    public function ended(Request $request): Response
    {
        $request->attributes->set(self::ENDED, true);

        return $this->signedOut($request, SignInReason::SignedOut);
    }

    /**
     * After a password reset that set the password but logged no one in, because the login policy
     * wants another kind of login: the person's sessions were ended, so this clears the session
     * cookie, empties Laravel's session and redirects to the login page with the reason.
     */
    public function passwordChanged(Request $request): Response
    {
        return $this->signedOut($request, SignInReason::PasswordChanged);
    }

    /**
     * Once the kernel has handled a request that ended the sessions: Laravel's own middleware has
     * stored the empty session it started after the logout and set its cookie, so this destroys
     * that session in its store and replaces the cookie with one that clears it. Every other
     * request is left as it is.
     */
    public function clearCookies(Request $request, HttpResponse $response): void
    {
        if ($request->attributes->get(self::ENDED) !== true || ! $request->hasSession()) {
            return;
        }

        $laravel = $request->session();
        $laravel->getHandler()->destroy($laravel->getId());

        $config = $this->config->get('session');
        $config = is_array($config) ? $config : [];
        $path = $config['path'] ?? '/';
        $domain = $config['domain'] ?? null;
        $sameSite = match ($config['same_site'] ?? null) {
            'lax', 'Lax' => 'lax',
            'strict', 'Strict' => 'strict',
            'none', 'None' => 'none',
            default => null,
        };

        $response->headers->setCookie(Cookie::create(
            $laravel->getName(),
            null,
            1,
            is_string($path) ? $path : '/',
            is_string($domain) ? $domain : null,
            (bool) ($config['secure'] ?? false),
            true,
            false,
            $sameSite,
        ));
    }

    /**
     * The redirect to the login page with the reason, which also clears the session cookie and
     * empties Laravel's session, giving it a new id and CSRF token.
     */
    private function signedOut(Request $request, SignInReason $reason): Response
    {
        $laravel = $this->laravel($request);
        $laravel->invalidate();
        $laravel->regenerateToken();

        $response = $this->toLogin($request, $reason);
        $response->headers->setCookie($this->sessionCookie(null));

        return $response;
    }

    private function toLogin(Request $request, SignInReason $reason): Response
    {
        return $this->redirect($request, $this->urls->route(PanelRoute::Login->value, [SignInReason::PARAMETER => $reason->value], false));
    }

    /**
     * Binds Laravel's session to the CMS session of the token, first emptying it and giving it a
     * new id and CSRF token when it was bound to another one or to none.
     */
    private function bind(Session $laravel, SessionToken $token): void
    {
        $key = $token->hash();
        $bound = $laravel->get(self::BINDING);

        if (is_string($bound) && hash_equals($key, $bound)) {
            return;
        }

        $laravel->invalidate();
        $laravel->regenerateToken();
        $laravel->put(self::BINDING, $key);
    }

    /**
     * The session cookie with the id, or one that clears it when the id is null.
     */
    private function sessionCookie(?string $id): Cookie
    {
        return Cookie::create(
            $this->cookie->name,
            $id,
            $id === null ? 1 : 0,
            SessionCookie::PATH,
            null,
            $this->cookie->secure,
            SessionCookie::HTTP_ONLY,
            true,
            $this->cookie->sameSite->value,
        );
    }

    /**
     * The redirect across the login boundary, which is always a full page load: a page behind the
     * login carries the addons' import map and code, a credential page carries none (PRD 13.4),
     * and a document's import map cannot change once it is loaded. A browser follows the 303 with
     * a GET; an Inertia visit gets FULL_PAGE with the address in X-Inertia-Location, on which
     * Inertia loads the address as a new document instead of swapping the page in.
     */
    private function redirect(Request $request, string $location): Response
    {
        return $request->headers->get(Header::INERTIA) === 'true'
            ? new Response('', self::FULL_PAGE, [Header::LOCATION => $location])
            : new Response('', self::REDIRECT, ['Location' => $location]);
    }

    /**
     * @throws LogicException when the request has no Laravel session
     */
    private function laravel(Request $request): Session
    {
        if (! $request->hasSession()) {
            throw new LogicException('The panel needs Laravel\'s session: register its routes inside the web middleware group.');
        }

        return $request->session();
    }
}
