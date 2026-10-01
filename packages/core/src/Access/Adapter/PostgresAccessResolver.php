<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Core\Access\Domain\AccessCompiler;
use Cbox\Cms\Core\Access\Domain\AccessResolver;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Illuminate\Database\ConnectionResolverInterface;
use Override;

/**
 * Turns a verified principal into its AccessContext and sets it for the caller's transaction (PRD
 * 5.10, 6.2), on the default connection, or the one named.
 *
 * The anonymous principal gets AccessContext::anonymous(). For an actor it first sets a context
 * that names only the actor, with no regions and public access, because the app role reads the
 * grants of the context's actor and nothing else; it reads the actor's grants that have not ended
 * (a deactivation ends them, PRD 5.16) with their roles' ceilings and nodes' paths, compiles them
 * with the AccessCompiler and sets the compiled context, which it returns. The kernel runs it once
 * per command and read, after the credential verifier. Like ActorContext it needs the caller's open
 * transaction and throws TransactionRequired without one, before any statement.
 */
#[Internal]
final readonly class PostgresAccessResolver implements AccessResolver
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
    #[Override]
    public function resolve(Principal $principal): AccessContext
    {
        $context = new ActorContext($this->connections, $this->connection);

        if (! $principal instanceof ActorPrincipal) {
            $anonymous = AccessContext::anonymous();
            $context->set($anonymous);

            return $anonymous;
        }

        $context->set(new AccessContext($principal, [], ClassificationAccess::Public));
        $compiled = $this->compiler->compile($principal, new PostgresGrants($this->connections, $this->connection)->of($principal->actor));
        $context->set($compiled);

        return $compiled;
    }
}
