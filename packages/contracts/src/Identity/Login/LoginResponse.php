<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What came back for a pending login (PRD 5.16): the credentials the login form took
 * (SubmittedCredentials) or the parameters of the identity provider's callback
 * (CallbackParameters). Both are read from the request as they are; the connection decides.
 */
#[Experimental]
interface LoginResponse
{
    /**
     * The flow the response belongs to.
     */
    public function flow(): LoginFlow;

    /**
     * The state the response carries, as the request gave it; empty when it carries none.
     */
    public function state(): string;
}
