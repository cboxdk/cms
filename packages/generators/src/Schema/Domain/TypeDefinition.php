<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;

/**
 * A type in the M0 fixture schema: a handle, a label and at least one field. The fields are kept
 * sorted by handle, so the order in the schema file never changes the generated code.
 */
#[Internal]
final readonly class TypeDefinition
{
    public const int MAX_LABEL_LENGTH = 255;

    /** @var non-empty-list<FieldDefinition> sorted by handle */
    public array $fields;

    /**
     * @param  list<FieldDefinition>  $fields
     *
     * @throws GenerationFailed
     */
    public function __construct(
        public Handle $handle,
        public string $label,
        array $fields,
    ) {
        if (trim($label) === '' || preg_match('/\A[^\p{Cc}]{1,'.self::MAX_LABEL_LENGTH.'}\z/u', $label) !== 1) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaInvalid, sprintf(
                'The label of type "%s" must be one line of text with 1 to %d characters and no control characters.',
                $handle->value,
                self::MAX_LABEL_LENGTH,
            ));
        }

        if ($fields === []) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaInvalid, sprintf(
                'Type "%s" has no fields. Give it at least one field with a handle and a type.',
                $handle->value,
            ));
        }

        usort($fields, static fn (FieldDefinition $a, FieldDefinition $b): int => strcmp($a->handle->value, $b->handle->value));

        $duplicates = [];

        foreach ($fields as $index => $field) {
            $previous = $fields[$index - 1] ?? null;

            if ($previous !== null && $previous->handle->equals($field->handle)) {
                $duplicates[$field->handle->value] = $field->handle->value;
            }
        }

        if ($duplicates !== []) {
            throw GenerationFailed::because(GenerateErrorCode::DuplicateField, sprintf(
                'Type "%s" has more than one field with the handle %s. A field handle is unique within its type.',
                $handle->value,
                implode(', ', array_map(static fn (string $duplicate): string => '"'.$duplicate.'"', array_values($duplicates))),
            ));
        }

        $this->fields = $fields;
    }
}
