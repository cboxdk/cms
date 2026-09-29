<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Results;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Errors\ErrorCode;

/**
 * One reason a write was rejected (PRD 6.1): a code from the error catalog, the path of the input
 * it is about, or null when it is about the command as a whole, and the concrete cause in plain
 * language. The catalog entry of the code says how each surface answers it.
 */
#[Experimental]
final readonly class CatalogError
{
    public function __construct(
        public ErrorCode $code,
        public ?FieldPath $path,
        public string $message,
    ) {
        if (trim($message) === '') {
            throw InvalidWriteResult::emptyMessage($code);
        }
    }
}
