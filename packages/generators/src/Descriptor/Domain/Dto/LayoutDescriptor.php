<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Descriptor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The compiled layout of a type's form (gap G2): the type's tabs and sidebar with the fragments
 * of its extensions composed in, so that every top-level field of the type, the extension fields
 * included, is placed exactly once. The editor descriptor carries it; the type descriptor's
 * canonical JSON, the catalog, the codecs, the validators and the tables do not.
 */
#[Internal]
final readonly class LayoutDescriptor
{
    /**
     * @param  list<TabDescriptor>  $tabs  in form order, each handle once
     * @param  list<SectionDescriptor>  $sidebar  in form order, each handle once
     */
    public function __construct(
        public array $tabs,
        public array $sidebar,
    ) {}

    /**
     * The layout with a fragment composed in: a tab whose handle the layout has gets the
     * fragment's sections merged into it, a sidebar section whose handle the sidebar has gets
     * the placements appended, and the rest are appended.
     *
     * @param  list<TabDescriptor>  $tabs
     * @param  list<SectionDescriptor>  $sidebar
     */
    public function merged(array $tabs, array $sidebar): self
    {
        $merged = $this->tabs;

        foreach ($tabs as $tab) {
            $index = array_find_key($merged, static fn (TabDescriptor $existing): bool => $existing->handle->value === $tab->handle->value);

            if ($index === null) {
                $merged[] = $tab;
            } else {
                $merged[$index] = $merged[$index]->merged($tab->sections);
            }
        }

        return new self($merged, self::mergeSections($this->sidebar, $sidebar));
    }

    /**
     * Every path the layout places, tabs first and then the sidebar, in form order.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $sections = [];

        foreach ($this->tabs as $tab) {
            $sections = [...$sections, ...$tab->sections];
        }

        $sections = [...$sections, ...$this->sidebar];
        $paths = [];

        foreach ($sections as $section) {
            foreach ($section->fields as $placement) {
                $paths[] = $placement->path;
            }
        }

        return $paths;
    }

    /**
     * The sections with the others merged in: a section whose handle an earlier one has gets its
     * placements appended to that one, and the rest are appended.
     *
     * @param  list<SectionDescriptor>  $sections
     * @param  list<SectionDescriptor>  $others
     * @return list<SectionDescriptor>
     */
    public static function mergeSections(array $sections, array $others): array
    {
        foreach ($others as $other) {
            $index = array_find_key($sections, static fn (SectionDescriptor $existing): bool => $existing->handle->value === $other->handle->value);

            if ($index === null) {
                $sections[] = $other;
            } else {
                $sections[$index] = $sections[$index]->with($other->fields);
            }
        }

        return $sections;
    }
}
