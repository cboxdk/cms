<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access\Fixtures;

use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\AuthorizedOn;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * Aggregates that name the targets given and read nothing.
 */
final readonly class TargetedAggregates implements AuthorizedOn
{
    /** @var list<AuthorizationTarget> */
    private array $targets;

    public function __construct(AuthorizationTarget ...$targets)
    {
        $this->targets = array_values($targets);
    }

    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions;
    }

    #[Override]
    public function authorizationTargets(): array
    {
        return $this->targets;
    }
}
