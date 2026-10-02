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
 * - the command is on its list: COMMANDS, the commands that set up an installation, or for the
 *   one-time access bootstrap alone BOOTSTRAP_COMMANDS (forAccessBootstrap());
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
     * The commands the operator may run to set up an installation: the actor commands and the
     * command of the first site.
     *
     * @var list<string>
     */
    public const array COMMANDS = ['actor.activate', 'actor.register', 'site.register'];

    /**
     * The commands the operator may run in the one-time access bootstrap alone (PRD 5.10): the
     * bootstrap role and its grant to the first staff member. No other maintenance command gives
     * access, which belongs to staff members with roles.
     *
     * @var list<string>
     */
    public const array BOOTSTRAP_COMMANDS = ['grant.assign', 'role.create'];

    /**
     * @param  list<string>  $commands  the commands it allows, COMMANDS unless built for the bootstrap
     */
    public function __construct(
        private InstallationOperator $installation,
        private array $commands = self::COMMANDS,
    ) {}

    /**
     * The authorizer of the one-time access bootstrap's pipeline, which allows BOOTSTRAP_COMMANDS
     * and nothing else.
     */
    public static function forAccessBootstrap(InstallationOperator $installation): self
    {
        return new self($installation, self::BOOTSTRAP_COMMANDS);
    }

    #[Override]
    public function authorize(AccessContext $access, CommandName $command, Command $input, Aggregates $aggregates, Envelope $envelope): Authorization
    {
        if (! in_array($command->value, $this->commands, true)) {
            return Authorization::refuse(sprintf('A maintenance command runs only %s, not %s.', implode(', ', $this->commands), $command->value));
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
