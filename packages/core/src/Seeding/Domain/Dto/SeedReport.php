<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Operations\Domain\Dto\OperationProgress;

/**
 * What a seed run did: the request, where it wrote and the operation that ran its chunks.
 */
#[Internal]
final readonly class SeedReport
{
    public function __construct(
        public SeedRequest $request,
        public SeedScope $scope,
        public OperationProgress $operation,
    ) {}
}
