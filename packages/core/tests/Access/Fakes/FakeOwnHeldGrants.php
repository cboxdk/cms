<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access\Fakes;

use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Core\Access\Domain\Dto\HeldGrant;
use Cbox\Cms\Core\Access\Domain\OwnHeldGrants;
use Closure;
use Override;

/**
 * OwnHeldGrants without a database (OwnHeldGrantsBehaviour holds it to PostgresOwnHeldGrants): the
 * grants of the context's actor from FakePermissions, following the context the FakeAccessResolver
 * set for the read, so a pipeline over fakes reads each credential's own grants, as the Postgres
 * adapter reads the actor of `cms_access_actor()`; or a fixed list for a test of the action alone.
 * Without an actor context there are none.
 */
final readonly class FakeOwnHeldGrants implements OwnHeldGrants
{
    /**
     * @param  Closure(): list<HeldGrant>  $held
     */
    private function __construct(private Closure $held) {}

    /**
     * The grants of whichever actor the resolver's current context names.
     */
    public static function following(FakePermissions $permissions, FakeAccessResolver $access): self
    {
        return new self(static function () use ($permissions, $access): array {
            $principal = $access->current()?->principal;

            return $principal instanceof ActorPrincipal ? $permissions->held($principal->actor) : [];
        });
    }

    /**
     * A fixed list, as a read under one actor's context gives it.
     *
     * @param  list<HeldGrant>  $held
     */
    public static function of(array $held): self
    {
        return new self(static fn (): array => $held);
    }

    #[Override]
    public function held(): array
    {
        return ($this->held)();
    }
}
