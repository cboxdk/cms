<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Override;

/**
 * The authorization of the maintenance pipeline (PRD 5.16, 6.2 phase 2), which RunMaintenanceCommand
 * runs in a fresh installation, before any actor holds a grant. It allows a command only when all
 * of these hold, and refuses every other call as unauthorized:
 *
 * - the command is one of COMMANDS, the commands that set up an installation;
 * - the envelope comes from the internal issuer maintenance;
 * - the principal is the installation operator, acting on behalf of no one, and the envelope's
 *   actor is the operator too.
 *
 * It grants nothing else: the operator holds no grant, so the kernel's own authorizer refuses it
 * every command, and this authorizer runs only in the maintenance pipeline. A command added to
 * COMMANDS gets its maintenance command in the cli, which calls RunMaintenanceCommand with a stable
 * unit of work, and its case in MaintenanceAuthorizerTest (docs/developers/maintenance-commands.md).
 */
#[Internal]
final readonly class MaintenanceAuthorizer implements CommandAuthorizer
{
    /**
     * The commands the operator may run: the actor commands, and the commands of the first site,
     * the roles and the grants, which later tasks of block B1 add (site.register, role.create and
     * grant.assign).
     *
     * @var list<string>
     */
    public const array COMMANDS = ['actor.activate', 'actor.register', 'grant.assign', 'role.create', 'site.register'];

    public function __construct(private InstallationOperator $installation) {}

    #[Override]
    public function authorize(AccessContext $access, CommandName $command, Command $input, Aggregates $aggregates, Envelope $envelope): Authorization
    {
        if (! in_array($command->value, self::COMMANDS, true)) {
            return Authorization::refuse(sprintf('A maintenance command runs only %s, not %s.', implode(', ', self::COMMANDS), $command->value));
        }

        if ($envelope->surface !== IssuingSurface::Maintenance) {
            return Authorization::refuse(sprintf('%s runs as the installation operator only from the maintenance issuer, not from %s.', $command->value, $envelope->surface->value));
        }

        $operator = $this->installation->find();

        if (! $operator instanceof ActorId) {
            return Authorization::refuse('The installation has no operator. Run cms:install in the maintenance process first.');
        }

        $principal = $access->principal;

        if (! $principal instanceof ActorPrincipal || ! $principal->actor->equals($operator) || $principal->onBehalfOf !== [] || ! $envelope->actor->equals($operator)) {
            return Authorization::refuse(sprintf('A maintenance command runs only as the installation operator %s, acting on behalf of no one.', $operator->toString()));
        }

        return Authorization::allow();
    }
}
