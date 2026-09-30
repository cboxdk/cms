<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\ActorDeactivated;
use Cbox\Cms\Core\Identity\Domain\Events\ActorDeactivated as ActorDeactivatedEvent;
use Cbox\Cms\Core\Identity\Domain\Events\ActorDeactivatedV1;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;
use UnexpectedValueException;

/**
 * Writes ActorDeactivated in the commit (PRD 5.16, 6.2 phase 7): the actor becomes deactivated at
 * the context's version with its credential generation one higher and the source noted, and every
 * direct grant of it that has not ended ends with the changeset. It returns actor.deactivated.
 *
 * The app role writes no identity row and ends no grant itself, so the writer calls DEACTIVATE,
 * which runs as the owner role and only in the transaction of an actor.deactivate changeset by the
 * context's actor (see the migration that adds it). It is one statement whatever the number of
 * grants the actor has. It runs on the default connection, or the one named, inside the command
 * transaction, after the commit has locked the actor and checked its version.
 */
#[Internal]
final readonly class ActorDeactivatedWriter implements MutationWriter
{
    /** The deactivation of one actor, as the owner role. */
    public const string DEACTIVATE = 'select credential_generation, grants_ended from cms_identity_deactivate_actor(?::uuid, ?::bigint, ?, ?::uuid)';

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
        return ActorDeactivated::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof ActorDeactivated) {
            throw new InvalidArgumentException(sprintf('The actor deactivation writer writes ActorDeactivated, not %s.', $mutation::class));
        }

        $row = $this->connections->connection($this->connection)->selectOne(self::DEACTIVATE, [
            $mutation->actor->toString(),
            $context->version->value,
            $mutation->source->value,
            $context->changesetId->toString(),
        ], false);

        return [new ActorDeactivatedEvent($context->version->value, new ActorDeactivatedV1(
            $mutation->actor,
            $mutation->source,
            $this->count($row, 'credential_generation'),
            $this->count($row, 'grants_ended'),
        ))];
    }

    private function count(mixed $row, string $column): int
    {
        $value = is_object($row) && property_exists($row, $column) ? $row->{$column} : null;

        if (! is_int($value)) {
            throw new UnexpectedValueException(sprintf('The deactivation of an actor returns %s as an integer, got %s.', $column, get_debug_type($value)));
        }

        return $value;
    }
}
