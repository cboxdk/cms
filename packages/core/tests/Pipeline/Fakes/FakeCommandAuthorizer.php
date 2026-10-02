<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Fakes;

use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Access\Domain\Dto\RoleGrant;
use Cbox\Cms\Core\Access\Domain\EscalationGuard;
use Cbox\Cms\Core\Access\Domain\GuardedGrant;
use Cbox\Cms\Core\Access\Domain\PermissionRule;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissions;
use Override;

/**
 * Allows every command, or refuses every one with the reason given, and records what it was asked.
 * granting() decides as the kernel's PostgresCommandAuthorizer does, from grants and permissions
 * held in memory, the escalation guard on a command that gives a role included
 * (CommandAuthorizerBehaviour holds the two together).
 */
final class FakeCommandAuthorizer implements CommandAuthorizer
{
    /** @var list<array{AccessContext, CommandName, Command, Aggregates}> */
    public array $asked = [];

    public function __construct(
        private readonly ?string $refusal = null,
        private readonly ?FakePermissions $permissions = null,
    ) {}

    public static function granting(FakePermissions $permissions): self
    {
        return new self(permissions: $permissions);
    }

    #[Override]
    public function authorize(AccessContext $access, CommandName $command, Command $input, Aggregates $aggregates, Envelope $envelope): Authorization
    {
        $this->asked[] = [$access, $command, $input, $aggregates];

        if (! $this->permissions instanceof FakePermissions) {
            return $this->refusal === null ? Authorization::allow() : Authorization::refuse($this->refusal);
        }

        $principal = $access->principal;

        if (! $principal instanceof ActorPrincipal) {
            return Authorization::refuse(sprintf('The anonymous principal holds no role, so it may not run %s.', $command->value));
        }

        $scope = $aggregates->authorizationScope();
        $paths = $this->permissions->paths(array_map(static fn (AuthorizationTarget $target): NodeId => $target->node, $scope->targets));
        $authorization = $this->decide($command, $scope, $this->permissions->of($principal->actor, $command), $paths, 'the actor');

        foreach ($principal->onBehalfOf as $delegator) {
            if (! $authorization->allowed()) {
                break;
            }

            $authorization = $this->decide($command, $scope, $this->permissions->of($delegator, $command), $paths, sprintf('the actor %s it acts on behalf of', $delegator->toString()));
        }

        $escalation = $aggregates instanceof GuardedGrant ? $aggregates->escalation() : null;

        if (! $authorization->allowed() || ! $escalation instanceof RoleGrant) {
            return $authorization;
        }

        $node = $paths[$escalation->node->toString()] ?? null;

        if (! $node instanceof NodePath) {
            return Authorization::refuse(sprintf('The actor reaches no node %s to give a role on.', $escalation->node->toString()));
        }

        $guard = new EscalationGuard;
        $authorization = $guard->decide($escalation, $node, $this->permissions->held($principal->actor), $principal->classificationCeiling());

        foreach ($principal->onBehalfOf as $delegator) {
            if (! $authorization->allowed()) {
                break;
            }

            $authorization = $guard->decide($escalation, $node, $this->permissions->held($delegator), $principal->classificationCeiling(), sprintf('the actor %s it acts on behalf of', $delegator->toString()));
        }

        return $authorization;
    }

    /**
     * @param  list<Grant>  $permitted
     * @param  array<string, NodePath>  $paths
     */
    private function decide(CommandName $command, AuthorizationScope $scope, array $permitted, array $paths, string $whose): Authorization
    {
        if ($permitted === []) {
            return Authorization::refuse(sprintf('No role of %s may run %s.', $whose, $command->value));
        }

        return new PermissionRule()->command($command, $scope, $permitted, $paths);
    }
}
