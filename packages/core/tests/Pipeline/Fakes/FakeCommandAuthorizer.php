<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Fakes;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\AuthorizedOn;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Override;

/**
 * Allows every command, or refuses every one with the reason given, and records what it was asked.
 *
 * byGrants() gives one that decides as the kernel's GrantCommandAuthorizer does, from the
 * permissions given with permit() instead of grants: an actor may run a command on each target its
 * aggregates name when a permit names the actor, the command and the target's node, in the
 * target's locale or in every locale. It refuses the anonymous principal and aggregates that name
 * no target. A permit holds on its node alone; the tree's inheritance is the real authorizer's.
 */
final class FakeCommandAuthorizer implements CommandAuthorizer
{
    /** @var list<array{AccessContext, CommandName, Command, Aggregates}> */
    public array $asked = [];

    /** @var list<array{ActorId, CommandName, NodeId, list<Locale>|null}>|null */
    private ?array $permits = null;

    public function __construct(private readonly ?string $refusal = null) {}

    public static function byGrants(): self
    {
        $authorizer = new self;
        $authorizer->permits = [];

        return $authorizer;
    }

    /**
     * @param  list<Locale>|null  $locales  null for every locale
     */
    public function permit(ActorId $actor, CommandName $command, NodeId $node, ?array $locales = null): self
    {
        $this->permits ??= [];
        $this->permits[] = [$actor, $command, $node, $locales];

        return $this;
    }

    #[Override]
    public function authorize(AccessContext $access, CommandName $command, Command $input, Aggregates $aggregates): Authorization
    {
        $this->asked[] = [$access, $command, $input, $aggregates];

        if ($this->permits === null) {
            return $this->refusal === null ? Authorization::allow() : Authorization::refuse($this->refusal);
        }

        $principal = $access->principal;

        if (! $principal instanceof ActorPrincipal) {
            return Authorization::refuse('The anonymous principal runs no command.');
        }

        $targets = $aggregates instanceof AuthorizedOn ? $aggregates->authorizationTargets() : [];

        if ($targets === []) {
            return Authorization::refuse(sprintf('The command %s names no node its permission is checked on.', $command->value));
        }

        foreach ($targets as $target) {
            if (! $this->permitted($principal->actor, $command, $target)) {
                return Authorization::refuse(sprintf('No permit for %s on %s.', $command->value, $target->node->toString()));
            }
        }

        return Authorization::allow();
    }

    private function permitted(ActorId $actor, CommandName $command, AuthorizationTarget $target): bool
    {
        foreach ($this->permits ?? [] as [$permitted, $name, $node, $locales]) {
            if ($permitted->equals($actor) && $name->equals($command) && $node->equals($target->node)
                && ($locales === null || ! $target->locale instanceof Locale || array_any($locales, static fn (Locale $locale): bool => $locale->equals($target->locale)))) {
                return true;
            }
        }

        return false;
    }
}
