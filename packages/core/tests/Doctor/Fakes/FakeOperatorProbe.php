<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor\Fakes;

use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\CredentialGeneration;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\OperatorProbe;

/**
 * An installation whose operator the test sets; by default an active service actor.
 */
final class FakeOperatorProbe implements OperatorProbe
{
    public const string OPERATOR = '01936f5e-8a2b-7c3d-9e4f-0000000005a1';

    public function __construct(
        public ?Actor $actor = new Actor(new ActorId(new Uuid7(self::OPERATOR)), ActorClass::Service, ActorState::Active, 2, new CredentialGeneration(1)),
        public ?ProbeFailed $failure = null,
    ) {}

    public static function none(): self
    {
        return new self(null);
    }

    public static function of(ActorClass $class, ActorState $state): self
    {
        return new self(new Actor(ActorId::fromString(self::OPERATOR), $class, $state, 2, new CredentialGeneration(1)));
    }

    public function operator(): ?Actor
    {
        if ($this->failure instanceof ProbeFailed) {
            throw $this->failure;
        }

        return $this->actor;
    }
}
