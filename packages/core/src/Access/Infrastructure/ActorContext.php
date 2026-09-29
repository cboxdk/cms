<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\NodePath;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Sets the actor context that row level security reads (PRD 5.10), with SET LOCAL through
 * set_config(..., true), inside the caller's open transaction on the default connection, or the one
 * named. It is the one place that writes the settings; the access migration's functions read them.
 *
 * The context is set for every command and every read, reads included, and ends with the
 * transaction, so a pooler in transaction mode never hands it to another client. Without an open
 * transaction it throws TransactionRequired and sets nothing: outside one, SET LOCAL would end with
 * its own statement. Setting it again in the same transaction replaces every setting at once, as
 * the access resolver does after it has read the actor's grants.
 *
 * The settings: the principal (`actor` or `anonymous`), the actor's id, the paths of the regions
 * and of their exceptions as ltree arrays, and the classification access. The same statement sets
 * plan_cache_mode to force_custom_plan for the transaction, because a generic plan cannot use the
 * context's regions (PRD 5.10: connections that run RLS-heavy queries use custom plans).
 */
#[Internal]
final readonly class ActorContext
{
    public const string PRINCIPAL = 'cbox_cms.principal';

    public const string ACTOR = 'cbox_cms.actor';

    public const string ALLOWED = 'cbox_cms.access_allowed';

    public const string DENIED = 'cbox_cms.access_denied';

    public const string CLASSIFICATION = 'cbox_cms.classification';

    private const string SET = <<<'SQL'
        select set_config('cbox_cms.principal', ?, true),
            set_config('cbox_cms.actor', ?, true),
            set_config('cbox_cms.access_allowed', ?, true),
            set_config('cbox_cms.access_denied', ?, true),
            set_config('cbox_cms.classification', ?, true),
            set_config('plan_cache_mode', 'force_custom_plan', true)
        SQL;

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    /**
     * Sets the context for the rest of the caller's transaction.
     *
     * @throws TransactionRequired
     */
    public function set(AccessContext $context): void
    {
        $db = $this->db();

        if ($db->transactionLevel() < 1) {
            throw TransactionRequired::forAccessContext();
        }

        $principal = $context->principal;
        $paths = [];
        $exceptions = [];

        foreach ($context->regions as $region) {
            $paths[] = $region->path;
            array_push($exceptions, ...$region->exceptions);
        }

        $db->statement(self::SET, [
            $principal instanceof ActorPrincipal ? 'actor' : 'anonymous',
            $principal instanceof ActorPrincipal ? $principal->actor->toString() : '',
            $this->paths($paths),
            $this->paths($exceptions),
            $context->classificationAccess->value,
        ]);
    }

    /**
     * An ltree array literal. Node paths hold only letters, digits, underscores, hyphens and dots,
     * so no element needs quoting.
     *
     * @param  list<NodePath>  $paths
     */
    private function paths(array $paths): string
    {
        return '{'.implode(',', array_map(static fn (NodePath $path): string => $path->value, $paths)).'}';
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }
}
