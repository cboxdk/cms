<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Schema;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldNamespace;

/**
 * An extender's part of a type's composite version (PRD 11.2, 11.12 point 5): the namespace of the
 * fields it adds and the version of its extension, from 1.
 */
#[Experimental]
final readonly class ExtensionVersion
{
    public function __construct(
        public FieldNamespace $namespace,
        public int $version,
    ) {
        if ($version < 1) {
            throw InvalidTypeDefinition::version('the extension '.$namespace->value, $version);
        }
    }
}
