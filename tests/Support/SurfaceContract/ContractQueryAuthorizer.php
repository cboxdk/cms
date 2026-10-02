<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Cbox\Cms\Core\Reads\Domain\QueryAuthorizer;
use Override;

/**
 * The query authorizer of the query contract kernel: it allows every principal, or refuses every
 * one with the reason the current scenario gives. One instance serves every scenario, because a
 * surface keeps the query pipeline it was built with, as the router keeps a route's controller.
 */
final class ContractQueryAuthorizer implements QueryAuthorizer
{
    /** Why every principal is refused, or null while every one is allowed. */
    public ?string $refusal = null;

    #[Override]
    public function authorize(AccessContext $access, CommandName $query, Query $input): Authorization
    {
        return $this->refusal === null ? Authorization::allow() : Authorization::refuse($this->refusal);
    }
}
