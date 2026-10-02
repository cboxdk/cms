<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Credentials\Boundary;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\CredentialForm;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Illuminate\Http\Request;

/**
 * The credential of a request to the Inertia profile (PRD 5.16, GUARDRAILS 2.1): the session of a
 * person who logged in, when the panel's middleware has authenticated the request from its session
 * cookie and put the session credential on the request under ATTRIBUTE, and otherwise the token of
 * the Authorization header (BearerCredential).
 *
 * The http module never reads a cookie: the panel, which knows the session cookie, puts the
 * credential there only after it has verified it and checked the request's CSRF token, so a session
 * reaches a command only through a request the person's own panel page sent. The REST surface takes
 * the Bearer token alone.
 */
#[Experimental]
final readonly class RequestCredential
{
    /** The request attribute that holds the session credential the panel authenticated. */
    public const string ATTRIBUTE = 'cbox-cms.credential.session';

    public static function of(Request $request): ?TransportCredential
    {
        $session = $request->attributes->get(self::ATTRIBUTE);

        if ($session instanceof TransportCredential && $session->form === CredentialForm::Session) {
            return $session;
        }

        return BearerCredential::of($request);
    }

    /**
     * Puts the session credential the panel authenticated on the request.
     */
    public static function authenticated(Request $request, TransportCredential $session): void
    {
        $request->attributes->set(self::ATTRIBUTE, $session);
    }
}
