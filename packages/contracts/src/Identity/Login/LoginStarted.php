<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;

/**
 * What LoginConnection::start() gives (PRD 5.16): the pending login the caller keeps server-side,
 * and, for the flow Redirect, the URL of the identity provider to send the browser to, which
 * carries the state. For the flow Direct there is no URL; the login form carries the state and takes
 * the credentials.
 */
#[Experimental]
final readonly class LoginStarted
{
    /**
     * @throws InvalidIdentity when a redirect is given for the flow Direct, none for the flow
     *                         Redirect, or the redirect is not an absolute http or https URL
     */
    public function __construct(public PendingLogin $pending, public ?string $redirectTo = null)
    {
        if (($pending->flow === LoginFlow::Redirect) !== ($redirectTo !== null)) {
            throw InvalidIdentity::loginValue('started login', 'given a redirect for the flow Redirect and none for the flow Direct');
        }

        if ($redirectTo !== null && preg_match('~\Ahttps?://[^/?#\s]+(?:[/?#]\S*)?\z~', $redirectTo) !== 1) {
            throw InvalidIdentity::loginValue('redirect', 'an absolute http or https URL');
        }
    }
}
