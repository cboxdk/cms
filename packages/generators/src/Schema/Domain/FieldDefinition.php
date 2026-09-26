<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A field of a type in the M0 fixture schema: its handle and the handle of its field type. The
 * set of field types is open in milestone 0; the blueprint schema v1 closes it in milestone 1.
 */
#[Internal]
final readonly class FieldDefinition
{
    public function __construct(
        public Handle $handle,
        public Handle $type,
    ) {}
}
