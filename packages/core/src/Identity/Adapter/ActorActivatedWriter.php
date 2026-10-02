<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\ActorActivated;
use Cbox\Cms\Core\Identity\Domain\Events\ActorActivated as ActorActivatedEvent;
use Cbox\Cms\Core\Identity\Domain\Events\ActorActivatedV1;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;

/**
 * Writes ActorActivated in the commit (PRD 5.16, 6.2 phase 7): the pending actor becomes active at
 * the context's version. It returns actor.activated.
 *
 * The app role writes no identity row, so the writer calls ACTIVATE, which runs as the owner role
 * and only in the transaction of an actor.activate changeset by the context's actor (see the
 * migration that adds it). It runs on the default connection, or the one named, inside the command
 * transaction, after the commit has locked the actor and checked its version.
 */
#[Internal]
final readonly class ActorActivatedWriter implements MutationWriter
{
    /** The activation of one pending actor, as the owner role. */
    public const string ACTIVATE = 'select cms_identity_activate_actor(?::uuid, ?::bigint, ?::uuid)';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function writes(): string
    {
        return ActorActivated::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof ActorActivated) {
            throw new InvalidArgumentException(sprintf('The actor activation writer writes ActorActivated, not %s.', $mutation::class));
        }

        $this->connections->connection($this->connection)->statement(self::ACTIVATE, [
            $mutation->actor->toString(),
            $context->version->value,
            $context->changesetId->toString(),
        ]);

        return [new ActorActivatedEvent($context->version->value, new ActorActivatedV1($mutation->actor))];
    }
}
