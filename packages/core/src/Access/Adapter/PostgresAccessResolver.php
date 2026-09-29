<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Core\Access\Domain\AccessCompiler;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use UnexpectedValueException;

/**
 * Turns a verified principal into its AccessContext and sets it for the caller's transaction (PRD
 * 5.10, 6.2), on the default connection, or the one named.
 *
 * The anonymous principal gets AccessContext::anonymous(). For an actor it first sets a context
 * that names only the actor, with no regions and public access, because the app role reads the
 * grants of the context's actor and nothing else; it reads the actor's grants with their roles'
 * ceilings and nodes' paths, compiles them with the AccessCompiler and sets the compiled context,
 * which it returns. The kernel runs it once per command and read, after the credential verifier.
 * Like ActorContext it needs the caller's open transaction and throws TransactionRequired without
 * one, before any statement.
 */
#[Internal]
final readonly class PostgresAccessResolver
{
    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private AccessCompiler $compiler,
        private ?string $connection = null,
    ) {}

    /**
     * @throws TransactionRequired
     */
    public function resolve(Principal $principal): AccessContext
    {
        $context = new ActorContext($this->connections, $this->connection);

        if (! $principal instanceof ActorPrincipal) {
            $anonymous = AccessContext::anonymous();
            $context->set($anonymous);

            return $anonymous;
        }

        $context->set(new AccessContext($principal, [], ClassificationAccess::Public));
        $compiled = $this->compiler->compile($principal, $this->grants($principal));
        $context->set($compiled);

        return $compiled;
    }

    /**
     * @return list<Grant>
     */
    private function grants(ActorPrincipal $principal): array
    {
        $grants = [];

        foreach ($this->db()->table('grants as g')
            ->useWritePdo()
            ->join('roles as r', 'r.id', '=', 'g.role_id')
            ->join('nodes as n', 'n.id', '=', 'g.node_id')
            ->where('g.actor_id', $principal->actor->toString())
            ->orderBy('g.id')
            ->get(['g.role_id', 'r.classification_ceiling', 'n.path', 'g.effect', 'g.locales']) as $row) {
            $grants[] = $this->grant($row);
        }

        return $grants;
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
        $value = property_exists($row, $column) ? $row->{$column} : throw new UnexpectedValueException(sprintf('A grant row has the column %s.', $column));

        if ($value === null && $nullable) {
            return null;
        }

        return is_string($value) ? $value : throw new UnexpectedValueException(sprintf('The column %s of a grant row is text.', $column));
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
