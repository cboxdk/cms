<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Editors;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldHandle;

/**
 * A tab of a form (PRD 14.1): a labelled page of sections, each section handle once in the tab.
 */
#[Experimental]
final readonly class EditorTab
{
    /** @var list<EditorText> sorted by locale */
    public array $labels;

    /**
     * @param  list<EditorText>  $labels  the label per locale
     * @param  list<EditorSection>  $sections  in form order, each handle once
     *
     * @throws InvalidTypeEditor when the tab breaks its rules
     */
    public function __construct(
        public FieldHandle $handle,
        public string $label,
        array $labels,
        public array $sections,
    ) {
        $what = 'the tab '.$handle->value;

        if ($label === '' || mb_strlen($label) > EditorField::MAX_LABEL) {
            throw InvalidTypeEditor::label($what);
        }

        $this->labels = EditorText::checked($labels, 'the label of '.$what);
        EditorLayout::assertSections($sections, $what);
    }

    /**
     * The paths the tab places, in form order.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = [];

        foreach ($this->sections as $section) {
            array_push($paths, ...$section->paths());
        }

        return $paths;
    }

    /**
     * The tab with only the placements of the paths given, without the sections that are left
     * empty, or null when no section is left.
     *
     * @param  array<string, true>  $paths
     */
    public function keeping(array $paths): ?self
    {
        $sections = EditorLayout::keepSections($this->sections, $paths);

        return $sections === [] ? null : new self($this->handle, $this->label, $this->labels, $sections);
    }
}
