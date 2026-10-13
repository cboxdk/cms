<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Descriptor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Dto\LocalizedText;
use Cbox\Cms\Generators\Schema\Domain\Handle;

/**
 * A tab of the compiled layout (gap G2): a labelled page of sections, each handle once.
 */
#[Internal]
final readonly class TabDescriptor
{
    /**
     * @param  list<SectionDescriptor>  $sections  in form order, each handle once
     */
    public function __construct(
        public Handle $handle,
        public LocalizedText $label,
        public array $sections,
    ) {}

    /**
     * The tab with the sections given: a section whose handle the tab has gets the placements
     * appended to it, and the rest are appended as sections.
     *
     * @param  list<SectionDescriptor>  $sections
     */
    public function merged(array $sections): self
    {
        return new self($this->handle, $this->label, LayoutDescriptor::mergeSections($this->sections, $sections));
    }
}
