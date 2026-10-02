<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use SensitiveParameter;

/**
 * The hash of a local account's password (PRD 5.16, "Lokale konti"): an Argon2id hash in PHP's
 * encoded form, `$argon2id$v=19$m=<memory>,t=<time>,p=<threads>$<salt>$<hash>`, as
 * password_hash() writes it and the CHECK of cms_identity.local_accounts holds it. The parameters
 * are part of the hash, so a hash made with other parameters than the installation's is rehashed
 * at the next login.
 *
 * It is secret: a hash lets someone guess the password offline. Only the identity module's store
 * and hasher read it, and no message, log entry or span repeats it; var_dump() and a stack trace
 * show only that it is a hash.
 */
#[Experimental]
final readonly class PasswordHash
{
    public const string PATTERN = '/\A\$argon2id\$v=19\$m=[0-9]+,t=[0-9]+,p=[0-9]+\$[A-Za-z0-9+\/]+\$[A-Za-z0-9+\/]+\z/';

    /**
     * @throws InvalidIdentity when the value is not an Argon2id hash in PHP's encoded form
     */
    public function __construct(#[SensitiveParameter] public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidIdentity::passwordHash();
        }
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => '[password hash]'];
    }
}
