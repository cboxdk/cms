<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * What the generators generate from: every type of the schema roots with its extensions applied,
 * sorted by name (`<owner>:<handle>`), each name once. SchemaResolver builds it from the
 * blueprints.
 */
#[Internal]
final readonly class ResolvedSchema
{
    /**
     * @param  list<ResolvedType>  $types  sorted by name, each name once
     */
    public function __construct(public array $types) {}
}
