<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\CredentialGeneration;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\ServiceCredentialToken;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Testkit\Identity\ActorChanges;
use Cbox\Cms\Testkit\Identity\IdentitySeeder;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;

/**
 * Seeds the core's identity tables on Postgres as the owner role (PRD 5.16), for tests that run
 * the core's ActorDirectory and CredentialVerifier against real Postgres.
 *
 * The app role may only read these tables, and their row level security lets only the owner write
 * (see the core's migration), so the seeder writes on the owner connection. It writes what the
 * fake keeps in memory, through the same rules: ServiceCredentialSpec::issue() for a credential and
 * ActorChanges for a change of an actor. A credential is stored as the SHA-256 of its token,
 * with its chain in `service_credential_delegations`; the token itself is only returned.
 */
#[Experimental]
final readonly class PostgresIdentitySeeder implements IdentitySeeder
{
    public const string ACTORS = 'actors';

    public const string CREDENTIALS = 'service_credentials';

    public const string DELEGATIONS = 'service_credential_delegations';

    public function __construct(
        private ConnectionResolverInterface $connections,
        private Clock $clock,
        private IdGenerator $ids,
        private string $ownerConnection = 'pgsql_owner',
    ) {}

    public function addActor(ActorClass $class, ActorState $state = ActorState::Active): Actor
    {
        $actor = new Actor(new ActorId($this->ids->next()), $class, $state, Actor::FIRST_VERSION, CredentialGeneration::first());

        $this->owner()->table(self::ACTORS)->insert([
            'id' => $actor->id->toString(),
            'actor_class' => $class->value,
            'state' => $state->value,
            'version' => $actor->version,
            'credential_generation' => $actor->credentialGeneration->value,
            'created_at' => $this->timestamp($this->clock->now()),
        ]);

        return $actor;
    }

    public function issue(ServiceCredentialSpec $spec): TransportCredential
    {
        $now = $this->clock->now();
        $issued = $spec->issue($this->actor($spec->actor), array_map($this->actor(...), $spec->onBehalfOf), $now);
        $token = ServiceCredentialToken::fromSecret(random_bytes(ServiceCredentialToken::SECRET_BYTES));
        $id = $this->ids->next()->value;

        $delegations = [];

        foreach ($issued->onBehalfOf as $position => $actor) {
            $delegations[] = ['credential_id' => $id, 'position' => $position, 'actor_id' => $actor->toString()];
        }

        $this->owner()->transaction(function (ConnectionInterface $owner) use ($id, $token, $issued, $now, $delegations): void {
            $owner->table(self::CREDENTIALS)->insert([
                'id' => $id,
                'actor_id' => $issued->actor->toString(),
                'secret_hash' => $token->hash(),
                'credential_generation' => $issued->generation->value,
                'issuer_kind' => $issued->issuerKind->value,
                'classification_ceiling' => $issued->ceiling->value,
                'expires_at' => $this->timestamp($issued->expiresAt),
                'created_at' => $this->timestamp($now),
            ]);

            if ($delegations !== []) {
                $owner->table(self::DELEGATIONS)->insert($delegations);
            }
        });

        return $token->credential();
    }

    public function changeState(ActorId $id, ActorState $state): Actor
    {
        return $this->write(ActorChanges::state($this->actor($id), $state));
    }

    public function revokeCredentials(ActorId $id): Actor
    {
        return $this->write(ActorChanges::revoke($this->actor($id)));
    }

    /**
     * Writes the changed actor over the version it was read at.
     */
    private function write(Actor $actor): Actor
    {
        $updated = $this->owner()->table(self::ACTORS)
            ->where('id', $actor->id->toString())
            ->where('version', $actor->version - 1)
            ->update([
                'state' => $actor->state->value,
                'version' => $actor->version,
                'credential_generation' => $actor->credentialGeneration->value,
            ]);

        if ($updated !== 1) {
            throw new LogicException(sprintf('The actor %s changed while the seeder changed it.', $actor->id->toString()));
        }

        return $actor;
    }

    /**
     * @throws InvalidIdentity when no actor has the id
     */
    private function actor(ActorId $id): Actor
    {
        $row = $this->owner()->table(self::ACTORS)
            ->where('id', $id->toString())
            ->first(['id', 'actor_class', 'state', 'version', 'credential_generation']);

        if (! is_object($row)) {
            throw InvalidIdentity::unknownActor($id);
        }

        $values = get_object_vars($row);

        return new Actor(
            $id,
            ActorClass::from($this->string($values, 'actor_class')),
            ActorState::from($this->string($values, 'state')),
            $this->int($values, 'version'),
            new CredentialGeneration($this->int($values, 'credential_generation')),
        );
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function string(array $values, string $column): string
    {
        $value = $values[$column] ?? null;

        return is_string($value) ? $value : throw new LogicException(sprintf('The column actors.%s is not text.', $column));
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function int(array $values, string $column): int
    {
        $value = $values[$column] ?? null;

        return is_int($value) ? $value : throw new LogicException(sprintf('The column actors.%s is not an integer.', $column));
    }

    private function timestamp(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    private function owner(): ConnectionInterface
    {
        return $this->connections->connection($this->ownerConnection);
    }
}
