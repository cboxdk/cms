<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Core\Access\Domain\ActorGrantsRef;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Access\Domain\Dto\HeldGrant;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use UnexpectedValueException;

/**
 * Reads an actor's grants and the paths of nodes on the default connection, or the one named,
 * under the actor context the caller's transaction holds (PRD 5.10): the app role reads only the
 * grants of the context's actor, the nodes those grants name and the nodes its regions reach, and
 * every actor reads the roles and their permissions. Every read uses the write PDO, the primary
 * the caller's transaction runs on. The grants of the actors the context's actor acts on behalf of
 * come from ofDelegator().
 */
#[Internal]
final readonly class PostgresGrants
{
    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    /**
     * The actor's grants that have not ended (a deactivation ends them, PRD 5.16), with their
     * roles' ceilings and nodes' paths; with a permission, only the grants of the roles whose
     * permissions name it.
     *
     * @return list<Grant>
     */
    public function of(ActorId $actor, ?CommandName $permission = null): array
    {
        $query = $this->db()->table('grants as g')
            ->useWritePdo()
            ->join('roles as r', 'r.id', '=', 'g.role_id')
            ->join('nodes as n', 'n.id', '=', 'g.node_id')
            ->where('g.actor_id', $actor->toString())
            ->whereNull('g.ended_changeset_id')
            ->orderBy('g.id');

        if ($permission instanceof CommandName) {
            $query->whereExists(static fn (Builder $permitted): Builder => $permitted
                ->from('role_permissions as p')
                ->whereColumn('p.role_id', 'g.role_id')
                ->where('p.command', $permission->value));
        }

        $grants = [];

        foreach ($query->get(['g.role_id', 'r.classification_ceiling', 'n.path', 'g.effect', 'g.locales']) as $row) {
            $grants[] = $this->grant($row);
        }

        return $grants;
    }

    /**
     * The grants of an actor the context's actor acts on behalf of (PRD 5.16), as of() gives the
     * grants of the context's actor: the app role reads no other actor's grants, so they are read
     * through the owner function cms_delegator_grants, which gives none for an actor no credential
     * of the context's actor is issued on behalf of, so such a chain reaches nothing.
     *
     * @return list<Grant>
     */
    public function ofDelegator(ActorId $actor, ?CommandName $permission = null): array
    {
        $grants = [];

        foreach ($this->db()->select(
            'select role_id, classification_ceiling, path, effect, locales from cms_delegator_grants(?, ?)',
            [$actor->toString(), $permission?->value],
            false,
        ) as $row) {
            $grants[] = $this->grant($row);
        }

        return $grants;
    }

    /**
     * The grants of() gives without a permission, each with its role's permissions, for the
     * escalation guard.
     *
     * @return list<HeldGrant>
     */
    public function held(ActorId $actor): array
    {
        return $this->withPermissions($this->of($actor));
    }

    /**
     * The grants ofDelegator() gives without a permission, each with its role's permissions, for
     * the escalation guard.
     *
     * @return list<HeldGrant>
     */
    public function heldByDelegator(ActorId $actor): array
    {
        return $this->withPermissions($this->ofDelegator($actor));
    }

    /**
     * The read of each actor's set of grants (ActorGrantsRef) at its version, in one statement,
     * also of an actor the app role may not read the grants of. The escalation guard reads them
     * before the grants it decides from, so a change that commits between the two reads makes the
     * commit find the set at another version.
     *
     * @param  list<ActorId>  $actors
     * @return list<ReadVersion>
     */
    public function sets(array $actors): array
    {
        $reads = [];

        foreach (ActorGrantVersions::of($this->db(), $actors) as $actor => $version) {
            $reads[] = ReadVersion::at(new ActorGrantsRef(ActorId::fromString($actor)), $version);
        }

        return $reads;
    }

    /**
     * The path of each node the context may read, by its id; a node it may not read, or that does
     * not exist, is left out.
     *
     * @param  list<NodeId>  $nodes
     * @return array<string, NodePath>
     */
    public function paths(array $nodes): array
    {
        if ($nodes === []) {
            return [];
        }

        $paths = [];

        foreach ($this->db()->table('nodes')
            ->useWritePdo()
            ->whereIn('id', array_values(array_unique(array_map(static fn (NodeId $node): string => $node->toString(), $nodes))))
            ->get(['id', 'path']) as $row) {
            $paths[$this->text($row, 'id')] = new NodePath($this->text($row, 'path'));
        }

        return $paths;
    }

    /**
     * Each grant with the permissions of its role, read in one statement; every actor reads the
     * roles' permissions.
     *
     * @param  list<Grant>  $grants
     * @return list<HeldGrant>
     */
    private function withPermissions(array $grants): array
    {
        if ($grants === []) {
            return [];
        }

        $permissions = [];

        foreach ($this->db()->table('role_permissions')
            ->useWritePdo()
            ->whereIn('role_id', array_values(array_unique(array_map(static fn (Grant $grant): string => $grant->role->toString(), $grants))))
            ->orderBy('command')
            ->get(['role_id', 'command']) as $row) {
            $permissions[$this->text($row, 'role_id')][] = new CommandName($this->text($row, 'command'));
        }

        return array_map(static fn (Grant $grant): HeldGrant => new HeldGrant($grant, $permissions[$grant->role->toString()] ?? []), $grants);
    }

    private function grant(mixed $row): Grant
    {
        if (! is_object($row)) {
            throw new UnexpectedValueException('A grant row is an object.');
        }

        $locales = $this->text($row, 'locales', nullable: true);

        return new Grant(
            RoleId::fromString($this->text($row, 'role_id')),
            ClassificationAccess::from($this->text($row, 'classification_ceiling')),
            new NodePath($this->text($row, 'path')),
            GrantEffect::from($this->text($row, 'effect')),
            $locales === null ? null : array_map(static fn (string $locale): Locale => new Locale($locale), $this->textArray($locales)),
        );
    }

    /**
     * @return ($nullable is true ? string|null : string)
     */
    private function text(object $row, string $column, bool $nullable = false): ?string
    {
        $value = property_exists($row, $column) ? $row->{$column} : throw new UnexpectedValueException(sprintf('A row has the column %s.', $column));

        if ($value === null && $nullable) {
            return null;
        }

        return is_string($value) ? $value : throw new UnexpectedValueException(sprintf('The column %s of a row is text.', $column));
    }

    /**
     * The elements of a text[] literal as Postgres writes it. Locales hold no character it quotes.
     *
     * @return list<string>
     */
    private function textArray(string $literal): array
    {
        if (preg_match('/\A\{([A-Za-z0-9-]+(?:,[A-Za-z0-9-]+)*)\}\z/', $literal, $match) !== 1) {
            throw new UnexpectedValueException(sprintf('The locales of a grant are a text array of locales, got "%s".', $literal));
        }

        return explode(',', $match[1]);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}
