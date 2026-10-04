<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Identity\Fakes;

use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Identity\Domain\Dto\ActorMe;
use Cbox\Cms\Core\Identity\Domain\OwnActorReader;
use Override;

/**
 * OwnActorReader in memory, read as the actor of a context (OwnActorReaderBehaviour holds it to
 * PostgresOwnActorReader). A test adds every actor's self; own() gives the reader's, and null for a
 * context without an actor or an actor the test did not add.
 */
final class FakeOwnActorReader implements OwnActorReader
{
    /** @var array<string, ActorMe> by actor id */
    private array $actors = [];

    public function __construct(private readonly ?ActorId $reader) {}

    public function add(ActorMe $actor): self
    {
        $this->actors[$actor->actor->toString()] = $actor;

        return $this;
    }

    #[Override]
    public function own(): ?ActorMe
    {
        return $this->reader instanceof ActorId ? $this->actors[$this->reader->toString()] ?? null : null;
    }
}
