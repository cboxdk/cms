<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;

/**
 * The `layout` of a type file, or the layout fragment of an extension file (gap G2): the tabs
 * and the sidebar sections that place the top-level fields of the file, each exactly once
 * (BlueprintRules). A fragment's tab or section whose handle an earlier one has joins it when
 * the layouts of a type are composed (LayoutCompiler); the rest are appended.
 */
#[Internal]
final readonly class Layout
{
    /**
     * @param  list<LayoutTab>  $tabs  in file order
     * @param  list<LayoutSection>  $sidebar  in file order
     */
    public function __construct(
        public array $tabs,
        public array $sidebar,
        public SourceLocation $location,
    ) {}

    /**
     * Every placement of the layout: the tabs' in order, then the sidebar's.
     *
     * @return list<LayoutField>
     */
    public function placements(): array
    {
        $placements = [];

        foreach ($this->tabs as $tab) {
            foreach ($tab->sections as $section) {
                array_push($placements, ...$section->fields);
            }
        }

        foreach ($this->sidebar as $section) {
            array_push($placements, ...$section->fields);
        }

        return $placements;
    }
}
