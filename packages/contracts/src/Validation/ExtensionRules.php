<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Validation;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldNamespace;

/**
 * The rules for the fields one extender adds to a type (PRD 11.12): its namespace and its fields,
 * which the input holds under `ext.<namespace>`. The fields have different handles.
 */
#[Experimental]
final readonly class ExtensionRules
{
    /**
     * @param  list<FieldRules>  $fields
     *
     * @throws InvalidRules
     */
    public function __construct(
        public FieldNamespace $namespace,
        public array $fields,
    ) {
        FieldRules::assertDistinct($fields);
    }
}
