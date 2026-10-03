<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\GrantAssigned;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Access\Domain\Commands\AssignGrant;
use Cbox\Cms\Core\Access\Domain\Dto\AssignGrantAggregates;
use Cbox\Cms\Core\Access\Domain\Dto\StoredRole;
use Cbox\Cms\Core\Access\Domain\GrantReader;
use Cbox\Cms\Core\Access\Domain\GrantSlotRef;
use Override;

/**
 * The write action of grant.assign (PRD 5.10, 6.2). resolve() reads the grant's id, the actor to
 * get it through the ActorDirectory, the role, whether the actor holds the role on the node
 * already and the version of the actor's set of grants, through the GrantReader. The kernel's
 * authorize step holds the issuing actor to grant.assign on the node and an allow to the
 * escalation guard. refusals() refuses an actor that is not an active staff or service actor, a
 * role that does not exist, a locale set that is empty or names a locale twice, and a role the
 * actor holds on the node already, each with validation_failed, and plan() creates the grant.
 *
 * @implements WriteAction<AssignGrant, AssignGrantAggregates>
 * @implements RefusesCommand<AssignGrant, AssignGrantAggregates>
 */
#[Action(handles: AssignGrant::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Cli])]
#[Internal]
final readonly class AssignGrantAction implements RefusesCommand, WriteAction
{
    public function __construct(
        private ActorDirectory $actors,
        private GrantReader $grants,
    ) {}

    /**
     * @param  AssignGrant  $command
     */
    #[Override]
    public function resolve(Command $command): AssignGrantAggregates
    {
        $slot = new GrantSlotRef($command->actor, $command->role, $command->node);
        $existing = $this->grants->grant($command->grant);

        return new AssignGrantAggregates(
            $command->grant,
            $existing?->version,
            $this->actors->find($command->actor),
            $command->role,
            $this->grants->role($command->role),
            $slot,
            $this->grants->held($slot),
            $command->effect,
            $command->locales,
            $this->grants->actorGrants([$command->actor])[$command->actor->toString()] ?? AggregateVersion::first(),
        );
    }

    /**
     * @param  AssignGrant  $command
     * @param  AssignGrantAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        $refusals = [];

        if (! $aggregates->receives()) {
            $refusals[] = $this->invalid('actor', sprintf(
                'Only an active staff or service actor gets a grant (PRD 5.10, 5.16), and the actor %s %s.',
                $command->actor->toString(),
                $aggregates->actor instanceof Actor
                    ? sprintf('is a %s actor that is %s', $aggregates->actor->class->value, $aggregates->actor->state->value)
                    : 'does not exist',
            ));
        }

        if (! $aggregates->stored instanceof StoredRole) {
            $refusals[] = $this->invalid('role', sprintf('No role has the id %s.', $command->role->toString()));
        }

        $locales = $this->localeRefusal($command->locales);

        if ($locales instanceof CatalogError) {
            $refusals[] = $locales;
        }

        if ($aggregates->slotTaken) {
            $refusals[] = $this->invalid('role', sprintf(
                'The actor %s holds the role %s on the node %s already; revoke that grant to give it another effect or other locales.',
                $command->actor->toString(),
                $command->role->toString(),
                $command->node->toString(),
            ));
        }

        return $refusals;
    }

    /**
     * @param  AssignGrant  $command
     * @param  AssignGrantAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        return new Plan(new GrantAssigned($command->grant, $command->actor, $command->role, $command->node, $command->effect, $command->locales));
    }

    /**
     * @param  list<Locale>|null  $locales
     */
    private function localeRefusal(?array $locales): ?CatalogError
    {
        if ($locales === null) {
            return null;
        }

        if ($locales === []) {
            return $this->invalid('locales', 'A grant\'s locale set names at least one locale; null holds in every locale.');
        }

        $seen = [];

        foreach ($locales as $locale) {
            if (isset($seen[$locale->value])) {
                return $this->invalid('locales', sprintf('A grant\'s locale set names each locale once, and %s twice.', $locale->value));
            }

            $seen[$locale->value] = true;
        }

        return null;
    }

    private function invalid(string $path, string $message): CatalogError
    {
        return new CatalogError(ErrorCode::ValidationFailed, new FieldPath($path), $message);
    }
}
