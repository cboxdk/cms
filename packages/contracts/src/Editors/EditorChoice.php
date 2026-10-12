<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Editors;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldHandle;

/**
 * One option of a select field as the form offers it (PRD 11.12): the value the field stores,
 * a handle, and the label the blueprint gives it.
 */
#[Experimental]
final readonly class EditorChoice
{
    /**
     * @throws InvalidTypeEditor when the label is empty or too long
     */
    public function __construct(
        public FieldHandle $value,
        public string $label,
    ) {
        if ($label === '' || mb_strlen($label) > EditorField::MAX_LABEL) {
            throw InvalidTypeEditor::label('the choice '.$value->value);
        }
    }
}
