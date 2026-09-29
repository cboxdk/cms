<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Descriptor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * What the generators generate from: the descriptor of every type of the schema roots, sorted by
 * name (`<owner>:<handle>`), each name once. DescriptorCompiler builds it from the resolved schema.
 */
#[Internal]
final readonly class CompiledSchema
{
    /**
     * @param  list<TypeDescriptor>  $types  sorted by name, each name once
     */
    public function __construct(public array $types) {}
}
