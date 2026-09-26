<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;

/**
 * The workbench's fixture schema in milestone 0: at least one type, sorted by handle.
 *
 * The format is provisional. A schema file declares `format: m0-provisional`, and the format
 * carries only what the M0 generators need: types with a handle and a label, and fields with a
 * handle and a type. It does not pre-empt the blueprint schema v1 (PRD 11.12), which milestone 1
 * defines and which replaces this format and its parser.
 */
#[Internal]
final readonly class FixtureSchema
{
    public const string FORMAT = 'm0-provisional';

    /** @var non-empty-list<TypeDefinition> sorted by handle */
    public array $types;

    /**
     * @param  list<TypeDefinition>  $types
     *
     * @throws GenerationFailed
     */
    public function __construct(array $types)
    {
        if ($types === []) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaInvalid, 'The schema has no types. Declare at least one type under "types".');
        }

        usort($types, static fn (TypeDefinition $a, TypeDefinition $b): int => strcmp($a->handle->value, $b->handle->value));

        $duplicates = [];

        foreach ($types as $index => $type) {
            $previous = $types[$index - 1] ?? null;

            if ($previous !== null && $previous->handle->equals($type->handle)) {
                $duplicates[$type->handle->value] = $type->handle->value;
            }
        }

        if ($duplicates !== []) {
            throw GenerationFailed::because(GenerateErrorCode::DuplicateType, sprintf(
                'More than one type has the handle %s. A type handle is unique in the schema.',
                implode(', ', array_map(static fn (string $duplicate): string => '"'.$duplicate.'"', array_values($duplicates))),
            ));
        }

        $this->types = $types;
    }
}
