<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ActorProfile;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\ActorRegistered;
use Cbox\Cms\Core\Entries\Adapter\Timestamps;
use Cbox\Cms\Core\Identity\Domain\Events\ActorRegistered as ActorRegisteredEvent;
use Cbox\Cms\Core\Identity\Domain\Events\ActorRegisteredV1;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;
use UnexpectedValueException;

/**
 * Writes ActorRegistered in the commit (PRD 5.16, 6.2 phase 7): the actor, pending at the context's
 * version, which is 1 for the actor the command read as absent, at credential generation 1, with its
 * class, the person responsible for a service actor and the changeset's time, and its profile at
 * version 1. It returns actor.registered, which carries no part of the profile.
 *
 * The app role writes no identity row, so the writer calls REGISTER, which runs as the owner role
 * and only in the transaction of an actor.register changeset by the context's actor (see the
 * migration that adds it). It runs on the default connection, or the one named, inside the command
 * transaction, after the commit has locked the actor's id and the responsible person.
 */
#[Internal]
final readonly class ActorRegisteredWriter implements MutationWriter
{
    /** The registration of one actor with its profile, as the owner role. */
    public const string REGISTER = 'select cms_identity_register_actor(?::uuid, ?::bigint, ?, ?::uuid, ?, ?, ?::timestamptz, ?::uuid) as credential_generation';

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
        return ActorRegistered::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof ActorRegistered) {
            throw new InvalidArgumentException(sprintf('The actor registration writer writes ActorRegistered, not %s.', $mutation::class));
        }

        $profile = $mutation->profile;

        if (! $profile instanceof ActorProfile) {
            throw new InvalidArgumentException(sprintf('The registration of the actor %s has no profile; a hook\'s view of it leaves the profile out, the plan never does.', $mutation->actor->toString()));
        }

        $generation = $this->connections->connection($this->connection)->scalar(self::REGISTER, [
            $mutation->actor->toString(),
            $context->version->value,
            $mutation->class->value,
            $mutation->responsible instanceof ActorId ? $mutation->responsible->toString() : null,
            $profile->displayName->value,
            $profile->email->value,
            Timestamps::of($context->at),
            $context->changesetId->toString(),
        ], false);

        if (! is_int($generation)) {
            throw new UnexpectedValueException(sprintf('The registration of an actor returns its credential generation as an integer, got %s.', get_debug_type($generation)));
        }

        return [new ActorRegisteredEvent($context->version->value, new ActorRegisteredV1(
            $mutation->actor,
            $mutation->class,
            $mutation->responsible,
            $generation,
        ))];
    }
}
