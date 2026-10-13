<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Descriptor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * How a field type stores a value in a column of its type table (PRD 11.6): the Postgres type,
 * such as `text` or `numeric(10, 2)`, the CHECK expressions that hold the value to the field's
 * options, each over the quoted column it was asked for, and the kernel table whose `id` the
 * column references with a foreign key, such as `entries` for a to-one relation, or null.
 */
#[Internal]
final readonly class ColumnShape
{
    /**
     * @param  list<string>  $checks
     * @param  ?string  $references  the kernel table whose `id` the column references, ON DELETE RESTRICT
     */
    public function __construct(
        public string $type,
        public array $checks = [],
        public ?string $references = null,
    ) {}
}
