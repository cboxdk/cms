<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Identity\Doctor\Domain\Probes\LoginPolicyProbe;
use Cbox\Cms\Identity\LoginPolicy\Domain\InvalidLoginPolicy;
use Override;

/**
 * The login policy of this environment can be read and may hold in it (PRD 5.16): every key is in
 * its form, and outside local and testing a local staff login needs a passkey or two factors. The
 * identity module refuses to boot a process that serves HTTP with a policy that fails here, and
 * this check says why, from a console process that still boots.
 */
#[Internal]
final readonly class LoginPolicyCheck implements DoctorCheck
{
    public const string ID = 'identity.login_policy';

    public const string CODE = 'doctor_login_policy_invalid';

    public function __construct(private LoginPolicyProbe $probe) {}

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
        try {
            $this->probe->policy();
        } catch (InvalidLoginPolicy $invalid) {
            return CheckResult::fail(
                $this->id(),
                true,
                FailureKind::Violation,
                self::CODE,
                sprintf('The login policy of the environment %s cannot be used, and processes that serve HTTP refuse to boot with it.', $this->probe->environment()),
                $invalid->getMessage(),
                'Correct cbox-cms.identity.policy as the cause says, as docs/security/login-policy.md describes it, then run cms:doctor again.',
            );
        }

        return CheckResult::pass($this->id(), true, sprintf('The login policy of the environment %s can be read, and holds in it.', $this->probe->environment()));
    }
}
