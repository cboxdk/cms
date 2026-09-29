<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Validation;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Results\CatalogError;

/**
 * What a runtime validator found in input from outside (PRD 11.8, 11.12): every field error, each
 * with the path of the value it is about and the code of the rule it breaks, such as
 * validation_required or validation_too_long. A validator never throws for bad input; it returns
 * the errors. Input with errors is rejected as a whole with validation_failed, code(), and the
 * errors say which fields to correct.
 */
#[Experimental]
final readonly class ValidationReport
{
    /**
     * @param  list<CatalogError>  $errors  in the order of the rules' fields and the input's items
     */
    public function __construct(public array $errors) {}

    public function passed(): bool
    {
        return $this->errors === [];
    }

    /**
     * The code a command answers when the input has errors.
     */
    public function code(): ErrorCode
    {
        return ErrorCode::ValidationFailed;
    }
}
