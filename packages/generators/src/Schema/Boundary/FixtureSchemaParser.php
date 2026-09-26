<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\FieldDefinition;
use Cbox\Cms\Generators\Schema\Domain\FixtureSchema;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\TypeDefinition;

/**
 * Turns a decoded schema document into a FixtureSchema, or lists every problem in it at once.
 *
 * The M0 format, `format: m0-provisional`:
 *
 *     format: m0-provisional
 *     types:
 *       - handle: article
 *         label: Article
 *         fields:
 *           - handle: title
 *             type: text
 *
 * Every key shown is required, and no other key is allowed. Each problem names where it is, such
 * as `types[0].fields[1].handle`.
 */
#[Internal]
final readonly class FixtureSchemaParser
{
    /**
     * @param  string  $source  the schema file, for the messages
     *
     * @throws GenerationFailed
     */
    public function parse(mixed $document, string $source): FixtureSchema
    {
        $problems = [];
        $mapping = $this->mapping($document, ['format', 'types'], $source, '', $problems);

        if ($mapping === null) {
            throw GenerationFailed::with($problems === [] ? [$this->problem(GenerateErrorCode::SchemaInvalid, $source, '', 'is empty')] : $problems);
        }

        if (($mapping['format'] ?? null) !== FixtureSchema::FORMAT) {
            $problems[] = new GenerationProblem(GenerateErrorCode::SchemaFormat, sprintf(
                '%s: set "format: %s". It is the only format cms:generate reads in milestone 0. It is provisional and does not pre-empt the blueprint schema v1 of milestone 1.',
                $source,
                FixtureSchema::FORMAT,
            ));
        }

        $types = [];

        foreach ($this->list($mapping['types'] ?? null, $source, 'types', $problems) as $index => $value) {
            $type = $this->type($value, $source, sprintf('types[%d]', $index), $problems);

            if ($type instanceof TypeDefinition) {
                $types[] = $type;
            }
        }

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        try {
            return new FixtureSchema($types);
        } catch (GenerationFailed $failed) {
            throw GenerationFailed::with($this->located($failed, $source, ''), $failed);
        }
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function type(mixed $value, string $source, string $path, array &$problems): ?TypeDefinition
    {
        $known = count($problems);
        $type = $this->mapping($value, ['handle', 'label', 'fields'], $source, $path, $problems);

        if ($type === null) {
            return null;
        }

        $handle = $this->handle($type['handle'] ?? null, $source, $path.'.handle', $problems);
        $label = $type['label'] ?? null;

        if (! is_string($label)) {
            $problems[] = $this->problem(GenerateErrorCode::SchemaInvalid, $source, $path.'.label', 'must be a string');
        }

        $fields = [];

        foreach ($this->list($type['fields'] ?? null, $source, $path.'.fields', $problems) as $index => $field) {
            $fieldPath = sprintf('%s.fields[%d]', $path, $index);
            $mapping = $this->mapping($field, ['handle', 'type'], $source, $fieldPath, $problems);

            if ($mapping === null) {
                continue;
            }

            $fieldHandle = $this->handle($mapping['handle'] ?? null, $source, $fieldPath.'.handle', $problems);
            $fieldType = $this->handle($mapping['type'] ?? null, $source, $fieldPath.'.type', $problems);

            if ($fieldHandle instanceof Handle && $fieldType instanceof Handle) {
                $fields[] = new FieldDefinition($fieldHandle, $fieldType);
            }
        }

        // A type with a problem of its own is not built, so its invalid fields do not also show up as missing ones.
        if (! $handle instanceof Handle || ! is_string($label) || count($problems) > $known) {
            return null;
        }

        try {
            return new TypeDefinition($handle, $label, $fields);
        } catch (GenerationFailed $failed) {
            array_push($problems, ...$this->located($failed, $source, $path));

            return null;
        }
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function handle(mixed $value, string $source, string $path, array &$problems): ?Handle
    {
        if (! is_string($value)) {
            $problems[] = $this->problem(GenerateErrorCode::SchemaInvalid, $source, $path, 'must be a string');

            return null;
        }

        try {
            return new Handle($value);
        } catch (GenerationFailed $failed) {
            array_push($problems, ...$this->located($failed, $source, $path));

            return null;
        }
    }

    /**
     * A mapping with exactly the given keys, or null after recording why it is not one.
     *
     * @param  list<string>  $keys
     * @param  list<GenerationProblem>  $problems
     * @return array<string, mixed>|null
     */
    private function mapping(mixed $value, array $keys, string $source, string $path, array &$problems): ?array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $problems[] = $this->problem(GenerateErrorCode::SchemaInvalid, $source, $path, sprintf('must be a mapping with the keys %s', implode(', ', $keys)));

            return null;
        }

        $mapping = [];

        foreach ($value as $key => $item) {
            $mapping[(string) $key] = $item;
        }

        $missing = array_values(array_diff($keys, array_keys($mapping)));
        $unknown = array_values(array_diff(array_keys($mapping), $keys));

        if ($missing !== []) {
            $problems[] = $this->problem(GenerateErrorCode::SchemaInvalid, $source, $path, sprintf('is missing %s', implode(', ', $missing)));
        }

        if ($unknown !== []) {
            $problems[] = $this->problem(GenerateErrorCode::SchemaInvalid, $source, $path, sprintf(
                'has the unknown %s %s; the allowed keys are %s',
                count($unknown) === 1 ? 'key' : 'keys',
                implode(', ', $unknown),
                implode(', ', $keys),
            ));
        }

        return $mapping;
    }

    /**
     * A non-empty list, or an empty array after recording why it is not one.
     *
     * @param  list<GenerationProblem>  $problems
     * @return list<mixed>
     */
    private function list(mixed $value, string $source, string $path, array &$problems): array
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            $problems[] = $this->problem(GenerateErrorCode::SchemaInvalid, $source, $path, 'must be a list with at least one item');

            return [];
        }

        return $value;
    }

    /**
     * The domain's problems, prefixed with where they are.
     *
     * @return non-empty-list<GenerationProblem>
     */
    private function located(GenerationFailed $failed, string $source, string $path): array
    {
        return array_map(
            fn (GenerationProblem $problem): GenerationProblem => $this->problem($problem->code, $source, $path, $problem->message),
            $failed->problems,
        );
    }

    private function problem(GenerateErrorCode $code, string $source, string $path, string $message): GenerationProblem
    {
        return new GenerationProblem($code, $path === '' ? sprintf('%s: %s', $source, $message) : sprintf('%s, %s: %s', $source, $path, $message));
    }
}
