<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Validation;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\TypeId;

/**
 * The runtime validator of one type (PRD 11.8, 11.12): cms:generate writes one per type, in
 * app/Cms/Generated/Validators, from the schema version the code is generated from. It declares
 * the type's rules; the kernel's input validator, Cbox\Cms\Core\Validation\Boundary\InputValidator,
 * checks input from outside against them, such as a command's fields from the REST API, MCP or the
 * CLI, and the kernel checks a revision against the rules of the version it writes (invariant 4).
 */
#[Experimental]
interface TypeValidator
{
    /**
     * The id of the type, its blueprint's type_id.
     */
    public function type(): TypeId;

    public function rules(): TypeRules;
}
