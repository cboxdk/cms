<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Descriptor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Dto\LocalizedText;
use Cbox\Cms\Generators\Schema\Domain\Handle;

/**
 * A section of the compiled layout (gap G2): a run of placements in a tab or in the sidebar,
 * with the label, instructions and collapsibility its blueprint gave it.
 */
#[Internal]
final readonly class SectionDescriptor
{
    /**
     * @param  list<FieldPlacement>  $fields  in form order, each path once
     */
    public function __construct(
        public Handle $handle,
        public ?LocalizedText $label,
        public ?LocalizedText $instructions,
        public bool $collapsible,
        public array $fields,
    ) {}

    /**
     * The section with the placements appended.
     *
     * @param  list<FieldPlacement>  $placements
     */
    public function with(array $placements): self
    {
        return new self($this->handle, $this->label, $this->instructions, $this->collapsible, [...$this->fields, ...$placements]);
    }
}
