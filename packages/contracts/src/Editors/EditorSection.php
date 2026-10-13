<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Editors;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldHandle;

/**
 * A section of a form (PRD 14.1): a run of fields in a tab or in the sidebar, with an optional
 * label and instructions, which a form may let the editor collapse. The fields are placements of
 * top-level fields, each path once in the section; the layout holds every top-level field of the
 * type in exactly one section (TypeEditor).
 */
#[Experimental]
final readonly class EditorSection
{
    /** @var list<EditorText> sorted by locale */
    public array $labels;

    /** @var list<EditorText> sorted by locale */
    public array $instructionsByLocale;

    /**
     * @param  list<EditorText>  $labels  the label per locale
     * @param  list<EditorText>  $instructionsByLocale  the instructions per locale
     * @param  list<EditorPlacement>  $fields  in form order, each path once
     *
     * @throws InvalidTypeEditor when the section breaks its rules
     */
    public function __construct(
        public FieldHandle $handle,
        public ?string $label,
        array $labels,
        public ?string $instructions,
        array $instructionsByLocale,
        public bool $collapsible,
        public array $fields,
    ) {
        $what = 'the section '.$handle->value;

        if ($label === '' || mb_strlen($label ?? '') > EditorField::MAX_LABEL) {
            throw InvalidTypeEditor::label($what);
        }

        if ($instructions === '' || mb_strlen($instructions ?? '') > EditorText::MAX_TEXT) {
            throw InvalidTypeEditor::instructions($what);
        }

        $this->labels = EditorText::checked($labels, 'the label of '.$what);
        $this->instructionsByLocale = EditorText::checked($instructionsByLocale, 'the instructions of '.$what);
        $paths = [];

        foreach ($fields as $placement) {
            if (isset($paths[$placement->path])) {
                throw InvalidTypeEditor::placedTwice($placement->path);
            }

            $paths[$placement->path] = true;
        }
    }

    /**
     * The paths the section places, in form order.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return array_map(static fn (EditorPlacement $placement): string => $placement->path, $this->fields);
    }

    /**
     * The section with only the placements of the paths given, or null when none is left.
     *
     * @param  array<string, true>  $paths
     */
    public function keeping(array $paths): ?self
    {
        $kept = array_values(array_filter($this->fields, static fn (EditorPlacement $placement): bool => isset($paths[$placement->path])));

        return $kept === [] ? null : new self($this->handle, $this->label, $this->labels, $this->instructions, $this->instructionsByLocale, $this->collapsible, $kept);
    }
}
