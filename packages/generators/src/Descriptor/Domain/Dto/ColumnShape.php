<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Descriptor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * How a field type stores a value in a column of its type table (PRD 11.6): the Postgres type,
 * such as `text` or `numeric(10, 2)`, and the CHECK expressions that hold the value to the field's
 * options, each over the quoted column it was asked for.
 */
#[Internal]
final readonly class ColumnShape
{
    /**
     * @param  list<string>  $checks
     */
    public function __construct(
        public string $type,
        public array $checks = [],
    ) {}
}
