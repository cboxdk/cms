<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\GrantRevoked;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Access\Domain\Commands\RevokeGrant;
use Cbox\Cms\Core\Access\Domain\Dto\RevokeGrantAggregates;
use Cbox\Cms\Core\Access\Domain\Dto\StoredGrant;
use Cbox\Cms\Core\Access\Domain\GrantReader;
use Override;

/**
 * The write action of grant.revoke (PRD 5.10, 6.2). resolve() reads the grant, its role and the
 * version of its actor's set of grants through the GrantReader; the kernel compares the grant with the version the caller read, so a grant the
 * issuer's regions do not reach is version_conflict. The kernel's authorize step holds the issuing
 * actor to grant.revoke on the grant's node and the end of a deny to the escalation guard.
 * refusals() refuses a grant that has ended with validation_failed, and plan() ends it.
 *
 * @implements WriteAction<RevokeGrant, RevokeGrantAggregates>
 * @implements RefusesCommand<RevokeGrant, RevokeGrantAggregates>
 */
#[Action(handles: RevokeGrant::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Cli])]
#[Internal]
final readonly class RevokeGrantAction implements RefusesCommand, WriteAction
{
    public function __construct(private GrantReader $grants) {}

    /**
     * @param  RevokeGrant  $command
     */
    #[Override]
    public function resolve(Command $command): RevokeGrantAggregates
    {
        $grant = $this->grants->grant($command->grant);

        return new RevokeGrantAggregates(
            $command->grant,
            $grant,
            $grant instanceof StoredGrant ? $this->grants->role($grant->role) : null,
            $grant instanceof StoredGrant ? $this->grants->actorGrants([$grant->actor])[$grant->actor->toString()] ?? AggregateVersion::first() : AggregateVersion::first(),
        );
    }

    /**
     * @param  RevokeGrant  $command
     * @param  RevokeGrantAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        if ($aggregates->grant instanceof StoredGrant && ! $aggregates->grant->ended) {
            return [];
        }

        return [new CatalogError(ErrorCode::ValidationFailed, new FieldPath('grant'), sprintf(
            'The grant %s has ended already, so there is nothing to revoke.',
            $command->grant->toString(),
        ))];
    }

    /**
     * @param  RevokeGrant  $command
     * @param  RevokeGrantAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        return new Plan(new GrantRevoked($command->grant));
    }
}
