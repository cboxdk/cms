<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;

/**
 * A mutation that carries values classified above public (PRD 12.2), such as the profile of an
 * actor actor.register creates. A hook sees the plan's mutations through a view filtered to the
 * classification access it gets (PRD 6.3, invariant 21): when that access does not allow the
 * mutation's classification, the view holds withoutClassified() instead, the same change with the
 * classified values left out. The kernel writes the mutation as planned.
 */
#[Experimental]
interface ClassifiedMutation extends Mutation
{
    /**
     * The highest classification of the values the mutation carries.
     */
    public function classification(): ClassificationAccess;

    /**
     * The same mutation with every value of the classification left out.
     */
    public function withoutClassified(): self;
}
