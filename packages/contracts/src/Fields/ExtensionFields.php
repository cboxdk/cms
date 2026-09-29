<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The fields one extender adds to a type it does not own, under the extender's namespace
 * (PRD 11.12), as the generated code addresses them: ext.<namespace>.<handle>.
 */
#[Experimental]
final readonly class ExtensionFields
{
    public function __construct(
        public FieldNamespace $namespace,
        public FieldMap $fields,
    ) {}
}
