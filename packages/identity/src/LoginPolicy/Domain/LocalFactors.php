<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LoginPolicy\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The factors a local login of an actor class requires (PRD 5.16, "Loginpolitik"), set per
 * environment in cbox-cms.identity.policy.<class>.local_factors.
 */
#[Internal]
enum LocalFactors: string
{
    /** A single factor, such as a password, is enough. */
    case Password = 'password';

    /**
     * A passkey, or two factors: the amr claim names mfa or two methods. PRD 5.16 requires it for
     * a local staff login.
     */
    case PasskeyOrTwoFactors = 'passkey_or_two_factors';
}
