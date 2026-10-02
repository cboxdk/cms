<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How a transport carried a credential (PRD 5.16): as a bearer token, such as the Authorization
 * header of an API or MCP call, or as the session cookie of a person who logged in. A verifier
 * reads each form only as what it is, so a session id sent as a bearer token, or a token sent as
 * the session cookie, is never taken for the other.
 */
#[Experimental]
enum CredentialForm: string
{
    case Bearer = 'bearer';
    case Session = 'session';
}
