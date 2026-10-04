<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Core\Identity\Domain\Dto\ActorMe;
use Cbox\Cms\Core\Identity\Domain\OwnActorMissing;
use Cbox\Cms\Core\Identity\Domain\OwnActorReader;
use Cbox\Cms\Core\Identity\Domain\Queries\WhoAmI;
use Override;

/**
 * actor.me (PRD 5.16, 13.4): the principal's own actor with its profile and grants, read through
 * OwnActorReader under the read's actor context, so the answer can be nothing but the reader's own
 * self. Every actor may run it; the pipeline refuses the anonymous principal. It costs one unit:
 * the actor, its profile and its grants are a few rows by key.
 *
 * @implements QueryAction<WhoAmI, ActorMe>
 */
#[Action(handles: WhoAmI::class, surfaces: [Surface::Rest, Surface::Inertia])]
#[Internal]
final readonly class WhoAmIAction implements QueryAction
{
    /** What a read of one's own self costs. */
    public const int COST = 1;

    public function __construct(private OwnActorReader $reader) {}

    #[Override]
    public function cost(Query $query): QueryCost
    {
        return new QueryCost(self::COST);
    }

    /**
     * @throws OwnActorMissing when the context names no actor the reader can read
     */
    #[Override]
    public function handle(Query $query): ActorMe
    {
        return $this->reader->own() ?? throw OwnActorMissing::noActor();
    }
}
