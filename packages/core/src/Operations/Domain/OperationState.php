<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Operations\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Where an operation stands. A running operation resumes at its first chunk that has not
 * completed; completed and failed are final. An operation fails only when laravel-operations'
 * stall sweep or an operator fails it; a chunk that throws leaves it running.
 */
#[Experimental]
enum OperationState: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
