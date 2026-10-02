<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Identity\Doctor\Domain\Probes\PasswordHashingProbe;
use Override;

/**
 * The local accounts hash their passwords with Argon2id (PRD 5.16), so PHP must offer it.
 */
#[Internal]
final readonly class Argon2idCheck implements DoctorCheck
{
    public const string ID = 'identity.argon2id';

    public const string CODE = 'doctor_argon2id_unavailable';

    public function __construct(private PasswordHashingProbe $hashing) {}

    #[Override]
    public function id(): CheckId
    {
        return new CheckId(self::ID);
    }

    #[Override]
    public function blocking(): bool
    {
        return true;
    }

    #[Override]
    public function requires(): array
    {
        return [];
    }

    #[Override]
    public function run(): CheckResult
    {
        if (! $this->hashing->argon2id()) {
            return CheckResult::fail(
                $this->id(),
                true,
                FailureKind::Violation,
                self::CODE,
                'This PHP cannot hash passwords with Argon2id, which the local accounts use.',
                sprintf('PASSWORD_ARGON2ID is not available in PHP %s.', PHP_VERSION),
                'Use a PHP build with Argon2 support, through libargon2 or libsodium, such as the php-baseimages images.',
            );
        }

        return CheckResult::pass($this->id(), true, 'PHP hashes passwords with Argon2id.');
    }
}
