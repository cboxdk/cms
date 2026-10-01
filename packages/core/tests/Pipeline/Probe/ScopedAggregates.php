<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Probe;

use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * Aggregates that read nothing and name the authorization scope a test gives them.
 */
final readonly class ScopedAggregates implements Aggregates
{
    public function __construct(private AuthorizationScope $scope) {}

    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions;
    }

    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return $this->scope;
    }
}
