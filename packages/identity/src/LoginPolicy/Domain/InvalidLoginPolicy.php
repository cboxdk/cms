<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LoginPolicy\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;

/**
 * The login policy in cbox-cms.identity.policy is invalid (PRD 5.16). The message names the key and
 * the form it needs, never its value.
 */
#[Internal]
final class InvalidLoginPolicy extends InvalidArgumentException
{
    public const string CODE = 'login_policy_invalid';

    public static function key(string $key, string $form): self
    {
        return new self("The login policy is invalid: {$key} must be {$form}.");
    }
}
