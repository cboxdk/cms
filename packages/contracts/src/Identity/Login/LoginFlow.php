<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How a login connection takes the person's proof (PRD 5.16).
 */
#[Experimental]
enum LoginFlow: string
{
    /** The login page takes the credentials and the connection checks them: a local account's password. */
    case Direct = 'direct';

    /** The person is sent to the identity provider and comes back to the callback: OpenID Connect. */
    case Redirect = 'redirect';
}
