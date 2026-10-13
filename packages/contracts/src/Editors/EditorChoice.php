<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Editors;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldHandle;

/**
 * One option of a select field as the form offers it (PRD 11.12): the value the field stores,
 * a handle, the label the blueprint gives it and the label per locale it names.
 */
#[Experimental]
final readonly class EditorChoice
{
    /** @var list<EditorText> sorted by locale */
    public array $labels;

    /**
     * @param  list<EditorText>  $labels  the label per locale
     *
     * @throws InvalidTypeEditor when the label is empty or too long
     */
    public function __construct(
        public FieldHandle $value,
        public string $label,
        array $labels = [],
    ) {
        if ($label === '' || mb_strlen($label) > EditorField::MAX_LABEL) {
            throw InvalidTypeEditor::label('the choice '.$value->value);
        }

        $this->labels = EditorText::checked($labels, 'the label of the choice '.$value->value);
    }
}
