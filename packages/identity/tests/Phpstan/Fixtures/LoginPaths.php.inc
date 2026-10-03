<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Phpstan\Fixtures;

use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\CredentialGeneration;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Identity\LoginPolicy\Actions\CheckLoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginAttempt;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\SessionLifetimes;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginDecision;
use Cbox\Cms\Identity\LoginPolicy\Domain\LocalFactors;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginMethod;
use DateTimeImmutable;

/**
 * Two login paths that issue a session, which takes a LoginDecision. The first asks the policy;
 * the second skips it and makes its own decision, which PHPStan refuses. composer.json leaves the
 * directory out of the class map, so Composer never loads the fixture.
 */
final readonly class LoginPaths
{
    public function __construct(private CheckLoginPolicy $policy) {}

    public function askingThePolicy(LoginAttempt $attempt): LoginDecision
    {
        return $this->policy->check($attempt);
    }

    public function skippingThePolicy(ActorId $actor, CredentialGeneration $generation): LoginDecision
    {
        return new LoginDecision(
            $actor,
            ActorClass::Staff,
            $generation,
            new ConnectionId('local'),
            LoginMethod::Password,
            LocalFactors::Password,
            new DateTimeImmutable('2026-10-02T09:00:00Z'),
            new SessionLifetimes(60, 720),
        );
    }
}
