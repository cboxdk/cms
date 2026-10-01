<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbe;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ScopedAggregates;
use Cbox\Cms\Core\Tests\Postgres\AccessWorld;
use Closure;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every CommandAuthorizer of the kernel does (PRD 5.10, 6.2 phase 2), held against the fake
 * the pipeline's tests use and the PostgresCommandAuthorizer: a command is allowed only through a
 * role whose permissions name it and that reaches every target of the action's scope in its
 * locale; a deny beats the allow it inherits, an allow below a deny reaches again, a grant limited
 * to some locales reaches only those, and a target in every locale must be reached in each. A
 * scope without targets needs the permission on some node, and the anonymous principal is refused.
 *
 * The tree is AccessWorld's: ROOT, with NEWS, SPORT below it and FOOTBALL below that, and CULTURE.
 * The actor of each test holds only the grants the test gives it.
 */
trait CommandAuthorizerBehaviour
{
    /**
     * A new active actor holding exactly the grants: each a role by handle (one role per handle in a
     * test), the names of its permissions, the id of the node, the effect and the locales, null for
     * every locale.
     *
     * @param  list<array{string, list<string>, string, GrantEffect, list<string>|null}>  $grants
     */
    abstract protected function grantedActor(array $grants): ActorPrincipal;

    abstract protected function commandAuthorizer(): CommandAuthorizer;

    /**
     * Runs the authorization with the principal's access context, as the pipeline does: inside its
     * command transaction with the context set.
     *
     * @param  Closure(AccessContext): Authorization  $authorize
     */
    abstract protected function within(Principal $principal, Closure $authorize): Authorization;

    #[Test]
    public function it_allows_a_command_whose_role_has_the_permission_and_reaches_the_node(): void
    {
        $actor = $this->grantedActor([['writer', ['entry.create', 'entry.publish'], AccessWorld::NEWS, GrantEffect::Allow, null]]);

        Assert::assertTrue($this->authorized($actor, 'entry.create', $this->on(AccessWorld::NEWS))->allowed());
        Assert::assertTrue($this->authorized($actor, 'entry.create', $this->on(AccessWorld::SPORT))->allowed());
        Assert::assertTrue($this->authorized($actor, 'entry.publish', $this->on(AccessWorld::FOOTBALL, 'da'))->allowed());
    }

    #[Test]
    public function it_refuses_a_command_the_actor_s_roles_lack_on_a_node_they_reach(): void
    {
        $actor = $this->grantedActor([
            ['writer', ['entry.create'], AccessWorld::NEWS, GrantEffect::Allow, null],
            ['viewer', [], AccessWorld::ROOT, GrantEffect::Allow, null],
        ]);

        $refusal = $this->authorized($actor, 'entry.publish', $this->on(AccessWorld::NEWS, 'da'));

        Assert::assertFalse($refusal->allowed());
        Assert::assertStringContainsString('entry.publish', (string) $refusal->reason);
        Assert::assertFalse($this->authorized($actor, 'variant.release', AuthorizationScope::anywhere())->allowed());
    }

    #[Test]
    public function it_refuses_a_node_outside_the_grants_of_the_roles_with_the_permission(): void
    {
        $actor = $this->grantedActor([
            ['writer', ['entry.create'], AccessWorld::NEWS, GrantEffect::Allow, null],
            ['culture_viewer', ['entry.revise'], AccessWorld::CULTURE, GrantEffect::Allow, null],
        ]);

        Assert::assertFalse($this->authorized($actor, 'entry.create', $this->on(AccessWorld::CULTURE))->allowed());
        Assert::assertFalse($this->authorized($actor, 'entry.create', $this->on(AccessWorld::ROOT))->allowed());
        Assert::assertFalse($this->authorized($actor, 'entry.create', AuthorizationScope::on(
            new AuthorizationTarget(NodeId::fromString(AccessWorld::NEWS)),
            new AuthorizationTarget(NodeId::fromString(AccessWorld::CULTURE)),
        ))->allowed());
        Assert::assertTrue($this->authorized($actor, 'entry.revise', $this->on(AccessWorld::CULTURE))->allowed());
    }

    #[Test]
    public function it_keeps_a_denied_subtree_out_and_reaches_an_allow_below_the_deny_again(): void
    {
        $actor = $this->grantedActor([
            ['desk', ['entry.revise'], AccessWorld::NEWS, GrantEffect::Allow, null],
            ['desk', ['entry.revise'], AccessWorld::SPORT, GrantEffect::Deny, null],
            ['desk', ['entry.revise'], AccessWorld::FOOTBALL, GrantEffect::Allow, null],
        ]);

        Assert::assertTrue($this->authorized($actor, 'entry.revise', $this->on(AccessWorld::NEWS))->allowed());
        Assert::assertFalse($this->authorized($actor, 'entry.revise', $this->on(AccessWorld::SPORT))->allowed());
        Assert::assertTrue($this->authorized($actor, 'entry.revise', $this->on(AccessWorld::FOOTBALL))->allowed());
    }

    #[Test]
    public function it_refuses_a_locale_the_grant_excludes_and_content_shared_by_every_locale(): void
    {
        $actor = $this->grantedActor([['danish_desk', ['placement.set_window', 'entry.revise'], AccessWorld::NEWS, GrantEffect::Allow, ['da']]]);

        Assert::assertTrue($this->authorized($actor, 'placement.set_window', $this->on(AccessWorld::NEWS, 'da'))->allowed());
        Assert::assertFalse($this->authorized($actor, 'placement.set_window', $this->on(AccessWorld::NEWS, 'en'))->allowed());
        Assert::assertFalse($this->authorized($actor, 'entry.revise', $this->on(AccessWorld::NEWS))->allowed());
        Assert::assertTrue($this->authorized($actor, 'entry.revise', AuthorizationScope::anywhere())->allowed());
    }

    #[Test]
    public function it_keeps_a_node_out_of_every_locale_when_one_locale_is_denied(): void
    {
        $actor = $this->grantedActor([
            ['regional', ['entry.unpublish'], AccessWorld::ROOT, GrantEffect::Allow, null],
            ['regional', ['entry.unpublish'], AccessWorld::NEWS, GrantEffect::Deny, ['da']],
        ]);

        Assert::assertFalse($this->authorized($actor, 'entry.unpublish', $this->on(AccessWorld::NEWS))->allowed());
        Assert::assertFalse($this->authorized($actor, 'entry.unpublish', $this->on(AccessWorld::SPORT, 'da'))->allowed());
        Assert::assertTrue($this->authorized($actor, 'entry.unpublish', $this->on(AccessWorld::SPORT, 'en'))->allowed());
        Assert::assertTrue($this->authorized($actor, 'entry.unpublish', $this->on(AccessWorld::CULTURE))->allowed());
    }

    #[Test]
    public function it_allows_a_scope_without_targets_only_with_the_permission_on_some_node(): void
    {
        $actor = $this->grantedActor([
            ['admin', ['actor.deactivate'], AccessWorld::CULTURE, GrantEffect::Allow, null],
            ['revoked', ['entry.create'], AccessWorld::NEWS, GrantEffect::Deny, null],
        ]);

        Assert::assertTrue($this->authorized($actor, 'actor.deactivate', AuthorizationScope::anywhere())->allowed());
        Assert::assertFalse($this->authorized($actor, 'entry.create', AuthorizationScope::anywhere())->allowed());
    }

    #[Test]
    public function it_refuses_the_anonymous_principal(): void
    {
        $refusal = $this->authorized(new AnonymousPrincipal, 'entry.create', AuthorizationScope::anywhere());

        Assert::assertFalse($refusal->allowed());
        Assert::assertStringContainsString('anonymous', (string) $refusal->reason);
    }

    private function authorized(Principal $principal, string $command, AuthorizationScope $scope): Authorization
    {
        $input = new RenameProbe(
            EntryId::fromString('0192a0c0-0000-7000-8000-0000000000d1'),
            TypeId::fromString(AccessWorld::TYPE),
            NodeId::fromString(AccessWorld::NEWS),
            new FieldValues,
        );

        return $this->within($principal, fn (AccessContext $access): Authorization => $this->commandAuthorizer()->authorize(
            $access,
            new CommandName($command),
            $input,
            new ScopedAggregates($scope),
        ));
    }

    private function on(string $node, ?string $locale = null): AuthorizationScope
    {
        return AuthorizationScope::on(new AuthorizationTarget(NodeId::fromString($node), $locale === null ? null : new Locale($locale)));
    }
}
