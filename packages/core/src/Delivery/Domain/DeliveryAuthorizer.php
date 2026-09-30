<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Delivery\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Cbox\Cms\Core\Reads\Domain\QueryAuthorizer;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Override;

/**
 * The authorization of the delivery API's pipeline (PRD 5.10, 6.2, invariant 25), which runs
 * path.resolve only: published content is read through the delivery API by anyone, the anonymous
 * principal included, and row level security under the principal's context decides what the read
 * reaches. It refuses every other read.
 */
#[Internal]
final readonly class DeliveryAuthorizer implements QueryAuthorizer
{
    public const string QUERY = 'path.resolve';

    #[Override]
    public function authorize(AccessContext $access, CommandName $query, Query $input): Authorization
    {
        return $input instanceof ResolvePath && $query->value === self::QUERY
            ? Authorization::allow()
            : Authorization::refuse(sprintf('The delivery API runs only %s, not %s.', self::QUERY, $query->value));
    }
}
