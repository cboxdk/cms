<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Editors;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The place of one top-level field in a section of a form (PRD 14.1): the field's path, as the
 * descriptor's fields have it, and the width it takes in the section's row.
 */
#[Experimental]
final readonly class EditorPlacement
{
    /**
     * @throws InvalidTypeEditor when the path is empty
     */
    public function __construct(
        public string $path,
        public EditorWidth $width,
    ) {
        if ($path === '') {
            throw InvalidTypeEditor::placement();
        }
    }
}
