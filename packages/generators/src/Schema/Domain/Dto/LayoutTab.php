<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;

/**
 * A tab of a blueprint's `layout` (gap G2): a labelled page of sections.
 */
#[Internal]
final readonly class LayoutTab
{
    /**
     * @param  list<LayoutSection>  $sections  in file order
     */
    public function __construct(
        public Handle $handle,
        public LocalizedText $label,
        public array $sections,
        public SourceLocation $location,
    ) {}
}
