<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Editors\TypeEditor;
use Cbox\Cms\Contracts\Pipeline\DescribesTypes;
use Override;

/**
 * The result of type.list (PRD 11.12, 14.1): the editor descriptor of every type of the
 * installation, sorted by type name, each as the query pipeline left it for the reader.
 */
#[Experimental]
final readonly class TypeList implements DescribesTypes
{
    /**
     * @param  list<TypeEditor>  $types
     */
    public function __construct(
        public array $types,
    ) {}

    #[Override]
    public function editors(): array
    {
        return $this->types;
    }

    #[Override]
    public function withEditors(array $editors): static
    {
        return new self($editors);
    }
}
