<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutation;

/**
 * One mutation of a plan with the context the commit writes it in.
 */
#[Internal]
final readonly class PendingMutation
{
    public function __construct(
        public Mutation $mutation,
        public MutationContext $context,
    ) {}
}
