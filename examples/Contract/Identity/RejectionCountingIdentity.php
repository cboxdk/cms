<?php

declare(strict_types=1);

namespace Examples\Contract\Identity;

use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Testkit\Identity\IdentityHarness;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;

/**
 * The harness the shared suites run RejectionCountingVerifier through. It wraps the harness of the
 * decorated verifier: the seeder and the directory are that harness's, and the verifier is the
 * decorator over its verifier.
 */
final readonly class RejectionCountingIdentity implements IdentityHarness
{
    private RejectionCountingVerifier $verifier;

    public function __construct(private IdentityHarness $identity)
    {
        $this->verifier = new RejectionCountingVerifier($identity->verifier());
    }

    public function directory(): ActorDirectory
    {
        return $this->identity->directory();
    }

    public function verifier(): RejectionCountingVerifier
    {
        return $this->verifier;
    }

    public function addActor(ActorClass $class, ActorState $state = ActorState::Active): Actor
    {
        return $this->identity->addActor($class, $state);
    }

    public function issue(ServiceCredentialSpec $spec): TransportCredential
    {
        return $this->identity->issue($spec);
    }

    public function changeState(ActorId $id, ActorState $state): Actor
    {
        return $this->identity->changeState($id, $state);
    }

    public function revokeCredentials(ActorId $id): Actor
    {
        return $this->identity->revokeCredentials($id);
    }
}
