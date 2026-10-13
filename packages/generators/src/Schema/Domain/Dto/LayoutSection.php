<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;

/**
 * A section of a blueprint's `layout` (gap G2): a run of placements in a tab or in the sidebar,
 * with an optional label and instructions, which a form may let the editor collapse.
 */
#[Internal]
final readonly class LayoutSection
{
    public const bool DEFAULT_COLLAPSIBLE = false;

    /**
     * @param  list<LayoutField>  $fields  in file order
     */
    public function __construct(
        public Handle $handle,
        public ?LocalizedText $label,
        public ?LocalizedText $instructions,
        public bool $collapsible,
        public array $fields,
        public SourceLocation $location,
    ) {}
}
