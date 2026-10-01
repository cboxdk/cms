<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads\Fakes;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\PublicQuery;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Cbox\Cms\Core\Reads\Domain\QueryAuthorizer;
use Override;

/**
 * Allows every read, or refuses every one with the reason given, and records what it was asked.
 *
 * byGrants() gives one that decides as the kernel's GrantQueryAuthorizer does, from the
 * permissions given with permit() instead of grants: a PublicQuery runs for anyone, and any other
 * read for an actor a permit names with the query.
 */
final class FakeQueryAuthorizer implements QueryAuthorizer
{
    /** @var list<array{AccessContext, CommandName, Query}> */
    public array $asked = [];

    /** @var list<array{ActorId, CommandName}>|null */
    private ?array $permits = null;

    public function __construct(private readonly ?string $refusal = null) {}

    public static function byGrants(): self
    {
        $authorizer = new self;
        $authorizer->permits = [];

        return $authorizer;
    }

    public function permit(ActorId $actor, CommandName $query): self
    {
        $this->permits ??= [];
        $this->permits[] = [$actor, $query];

        return $this;
    }

    #[Override]
    public function authorize(AccessContext $access, CommandName $query, Query $input): Authorization
    {
        $this->asked[] = [$access, $query, $input];

        if ($this->permits === null) {
            return $this->refusal === null ? Authorization::allow() : Authorization::refuse($this->refusal);
        }

        if ($input instanceof PublicQuery) {
            return Authorization::allow();
        }

        $principal = $access->principal;

        if (! $principal instanceof ActorPrincipal) {
            return Authorization::refuse(sprintf('The anonymous principal runs only public reads, not %s.', $query->value));
        }

        foreach ($this->permits as [$actor, $permitted]) {
            if ($actor->equals($principal->actor) && $permitted->equals($query)) {
                return Authorization::allow();
            }
        }

        return Authorization::refuse(sprintf('No permit for %s.', $query->value));
    }
}
