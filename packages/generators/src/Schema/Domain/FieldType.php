<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A field type of the blueprint schema v1 (PRD 11.6, 13.3, GUARDRAILS 2.4): its name, the keys a
 * field of the type has beside the keys of every field, and how those keys become its options. A
 * FieldTypeContributor registers it in the FieldTypeRegistry, and the reader resolves the `type` of
 * every field there. The core's own types are registered the same way, by CoreFieldTypes.
 */
#[Internal]
interface FieldType
{
    /**
     * The name a blueprint file writes in a field's `type`, such as `text`.
     */
    public function name(): string;

    /**
     * The keys a field of the type may have beside the keys of every field, such as `max_length`.
     * The reader reports any other key as one it does not know.
     *
     * @return list<string>
     */
    public function optionKeys(): array;

    /**
     * The options of a field of the type, read from the field's values, or null when a value could
     * not be read; the values have then recorded the problem at its JSON pointer.
     */
    public function options(FieldValues $field): ?FieldOptions;
}
