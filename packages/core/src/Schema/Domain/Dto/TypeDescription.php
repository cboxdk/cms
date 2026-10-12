<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Editors\TypeEditor;
use Cbox\Cms\Contracts\Pipeline\DescribesTypes;
use Override;

/**
 * The result of type.describe (PRD 11.12, 14.1): the editor descriptor of the type named, as the
 * query pipeline left it for the reader, or null when the installation has no type of that name.
 */
#[Experimental]
final readonly class TypeDescription implements DescribesTypes
{
    public function __construct(
        public ?TypeEditor $type,
    ) {}

    #[Override]
    public function editors(): array
    {
        return $this->type instanceof TypeEditor ? [$this->type] : [];
    }

    #[Override]
    public function withEditors(array $editors): static
    {
        return new self($editors[0] ?? null);
    }
}
