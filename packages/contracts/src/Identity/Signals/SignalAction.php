<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What the core does for a security event it admits (PRD 5.16, "Signaler fra IdP'en"). Ending
 * sessions is not a command: it removes the sessions from Valkey and writes the authentication log,
 * so millions of logouts never become changesets. Every other action is the named command, since
 * state and grants change only through commands.
 */
#[Experimental]
enum SignalAction: string
{
    /** The subject's sessions end; no command. */
    case EndSessions = 'end_sessions';

    /** The command actor.deactivate, with the connection as its source. */
    case Deactivate = 'deactivate';

    /** The command actor.reactivate, which only the source that deactivated the actor may ask for. */
    case Reactivate = 'reactivate';

    /** The command actor.deprovision: a deactivation that also removes the link to the IdP identity. */
    case Deprovision = 'deprovision';

    /** The command actor.credentials_revoke: every session and token of the actor is refused at once. */
    case RevokeCredentials = 'revoke_credentials';

    /**
     * Whether the action is a command through the pipeline, as opposed to ending sessions.
     */
    public function isCommand(): bool
    {
        return $this !== self::EndSessions;
    }
}
