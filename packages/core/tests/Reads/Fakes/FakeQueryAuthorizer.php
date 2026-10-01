<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads\Fakes;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\PublicQuery;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Core\Access\Domain\PermissionRule;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Cbox\Cms\Core\Reads\Domain\QueryAuthorizer;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissions;
use Override;

/**
 * Allows every read, or refuses every one with the reason given, and records what it was asked.
 * granting() decides as the kernel's PostgresQueryAuthorizer does, from grants and permissions held
 * in memory (QueryAuthorizerBehaviour holds the two together).
 */
final class FakeQueryAuthorizer implements QueryAuthorizer
{
    /** @var list<array{AccessContext, CommandName, Query}> */
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
    public function authorize(AccessContext $access, CommandName $query, Query $input): Authorization
    {
        $this->asked[] = [$access, $query, $input];

        if (! $this->permissions instanceof FakePermissions) {
            return $this->refusal === null ? Authorization::allow() : Authorization::refuse($this->refusal);
        }

        if ($input instanceof PublicQuery) {
            return Authorization::allow();
        }

        $principal = $access->principal;

        if (! $principal instanceof ActorPrincipal) {
            return Authorization::refuse(sprintf('The anonymous principal may run only public reads, and %s is not one.', $query->value));
        }

        $authorization = new PermissionRule()->query($query, $this->permissions->of($principal->actor, $query));

        foreach ($principal->onBehalfOf as $delegator) {
            if (! $authorization->allowed()) {
                break;
            }

            $authorization = new PermissionRule()->query($query, $this->permissions->of($delegator, $query));
        }

        return $authorization;
    }
}
