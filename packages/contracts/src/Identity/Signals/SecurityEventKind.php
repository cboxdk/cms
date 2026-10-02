<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The security events the core acts on (PRD 5.16), by their event type URI: CAEP session-revoked
 * and credential-change, and RISC account-disabled, account-enabled, account-purged and
 * credential-compromise, as the OpenID Shared Signals Framework 1.0 defines them. Any other event
 * type is refused as signal_event_unsupported.
 */
#[Experimental]
enum SecurityEventKind: string
{
    case SessionRevoked = 'https://schemas.openid.net/secevent/caep/event-type/session-revoked';
    case CredentialChange = 'https://schemas.openid.net/secevent/caep/event-type/credential-change';
    case AccountDisabled = 'https://schemas.openid.net/secevent/risc/event-type/account-disabled';
    case AccountEnabled = 'https://schemas.openid.net/secevent/risc/event-type/account-enabled';
    case AccountPurged = 'https://schemas.openid.net/secevent/risc/event-type/account-purged';
    case CredentialCompromise = 'https://schemas.openid.net/secevent/risc/event-type/credential-compromise';

    /**
     * The kind of an event type, or null for a type the core does not act on.
     */
    public static function of(EventTypeUri $type): ?self
    {
        return self::tryFrom($type->value);
    }

    /**
     * What the core does for an event of the kind (PRD 5.16).
     */
    public function action(): SignalAction
    {
        return match ($this) {
            self::SessionRevoked, self::CredentialChange => SignalAction::EndSessions,
            self::AccountDisabled => SignalAction::Deactivate,
            self::AccountEnabled => SignalAction::Reactivate,
            self::AccountPurged => SignalAction::Deprovision,
            self::CredentialCompromise => SignalAction::RevokeCredentials,
        };
    }

    public function type(): EventTypeUri
    {
        return new EventTypeUri($this->value);
    }
}
