<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use RuntimeException;

/**
 * A query action rejects the read it handles with a code of the error catalog (PRD 6.1, 8.8):
 * thrown from QueryAction::handle(), the query pipeline turns it into the rejected QueryResult of
 * its CatalogError, which a surface answers with problem details, as the kernel's entry.get
 * answers a read of an entry out of the reader's reach with entry_not_found. The message says
 * what was rejected and what to do; a path names the value of the query's document it is about.
 */
#[Experimental]
final class ReadRejected extends RuntimeException
{
    public function __construct(public readonly CatalogError $error)
    {
        parent::__construct($error->message);
    }

    public static function with(ErrorCode $code, string $message, ?FieldPath $path = null): self
    {
        return new self(new CatalogError($code, $path, $message));
    }
}
