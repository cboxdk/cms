<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Operations\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Operations\Domain\ChunkedAction;
use Cbox\Cms\Core\Operations\Domain\OperationKey;

/**
 * One run of a chunked action. The action's kind and the key name the operation, so running the
 * same request again resumes it or, once it completed, does nothing.
 */
#[Experimental]
final readonly class OperationRequest
{
    public function __construct(
        public ChunkedAction $action,
        public OperationKey $key,
    ) {}
}
