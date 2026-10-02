<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\InvalidMutation;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * A grant is created (PRD 5.10): the actor gets the role on the node and the subtree below it,
 * allowing or denying, in the locales given or in every locale for null. From the commit on the
 * kernel compiles it into the actor's access. A locale set is not empty and names no locale twice.
 */
#[Experimental]
final readonly class GrantAssigned implements Mutation
{
    /**
     * @param  list<Locale>|null  $locales
     *
     * @throws InvalidMutation
     */
    public function __construct(
        public GrantId $grant,
        public ActorId $actor,
        public RoleId $role,
        public NodeId $node,
        public GrantEffect $effect,
        public ?array $locales = null,
    ) {
        if ($locales === null) {
            return;
        }

        if ($locales === []) {
            throw InvalidMutation::emptyLocales($grant);
        }

        $seen = [];

        foreach ($locales as $locale) {
            if (isset($seen[$locale->value])) {
                throw InvalidMutation::repeatedLocale($grant, $locale);
            }

            $seen[$locale->value] = true;
        }
    }

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->grant;
    }
}
