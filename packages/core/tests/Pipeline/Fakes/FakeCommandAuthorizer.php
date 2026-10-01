<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Fakes;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Core\Access\Domain\PermissionRule;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissions;
use Override;

/**
 * Allows every command, or refuses every one with the reason given, and records what it was asked.
 * granting() decides as the kernel's PostgresCommandAuthorizer does, from grants and permissions
 * held in memory (CommandAuthorizerBehaviour holds the two together).
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
    public function authorize(AccessContext $access, CommandName $command, Command $input, Aggregates $aggregates): Authorization
    {
        $this->asked[] = [$access, $command, $input, $aggregates];

        if (! $this->permissions instanceof FakePermissions) {
            return $this->refusal === null ? Authorization::allow() : Authorization::refuse($this->refusal);
        }

        $principal = $access->principal;

        if (! $principal instanceof ActorPrincipal) {
            return Authorization::refuse(sprintf('The anonymous principal holds no role, so it may not run %s.', $command->value));
        }

        $permitted = $this->permissions->of($principal->actor, $command);

        if ($permitted === []) {
            return Authorization::refuse(sprintf('No role of the actor may run %s.', $command->value));
        }

        $scope = $aggregates->authorizationScope();

        return new PermissionRule()->command(
            $command,
            $scope,
            $permitted,
            $this->permissions->paths(array_map(static fn (AuthorizationTarget $target): NodeId => $target->node, $scope->targets)),
        );
    }
}
