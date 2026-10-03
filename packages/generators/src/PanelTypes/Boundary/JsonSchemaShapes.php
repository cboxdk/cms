<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelTypes\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\JsonShape;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\ShapeDocument;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\ShapeProperty;
use Cbox\Cms\Generators\PanelTypes\Domain\ShapeKind;
use JsonException;

/**
 * Reads the JSON Schema of a contract's document, draft 2020-12 as the kernel's and an addon's
 * codecs carry it, into the shapes cms:panel:types types it with (PRD 13.4):
 *
 * - `type`, a type or a list of them, gives the kinds, `integer` and `number` both a number;
 * - `const` and `enum` give literal values, of a string, a number, a boolean or null;
 * - `properties`, `required` and `additionalProperties` give an object's members, those not
 *   required optional, and `items` an array's items;
 * - `anyOf` and `oneOf` give a union of their branches, `allOf` an intersection, and `$ref` to
 *   `#/$defs/<key>` the definition;
 * - `description` the comment.
 *
 * Keywords that only narrow the values TypeScript cannot narrow (the lengths, `pattern`,
 * `format`, `minimum`, `maximum`, `uniqueItems`, `propertyNames`, `if`, `then`, `else`, `not`,
 * `contains`, `unevaluatedProperties`, `default`, `examples`, `title` and the kernel's
 * classification) are left to the codec; a schema with any other keyword, such as
 * `patternProperties` or `prefixItems`, is refused, so no structure is ever typed wrong.
 */
#[Internal]
final readonly class JsonSchemaShapes
{
    /** The keywords that narrow values without changing the type, which the codec checks. */
    private const array NARROWING = [
        '$comment', '$id', '$schema', 'contains', 'default', 'deprecated', 'else', 'examples', 'exclusiveMaximum', 'exclusiveMinimum',
        'format', 'if', 'maxContains', 'maxItems', 'maxLength', 'maxProperties', 'maximum', 'minContains', 'minItems', 'minLength',
        'minProperties', 'minimum', 'multipleOf', 'not', 'pattern', 'propertyNames', 'readOnly', 'then', 'title', 'unevaluatedItems',
        'unevaluatedProperties', 'uniqueItems', 'writeOnly', 'x-cms-classification',
    ];

    /** The keywords whose structure the shapes carry. */
    private const array STRUCTURAL = [
        '$defs', '$ref', 'additionalProperties', 'allOf', 'anyOf', 'const', 'description', 'enum', 'items', 'oneOf', 'properties',
        'required', 'type',
    ];

    /** The prefix of a reference to a definition. */
    private const string DEFS = '#/$defs/';

    private function __construct(private string $what) {}

    /**
     * The shapes of the schema; $what names it in a refusal, such as "the schema of the command
     * reviews.request@1".
     *
     * @throws GenerationFailed with generate_schema_invalid
     */
    public static function read(string $json, string $what): ShapeDocument
    {
        $reader = new self($what);

        try {
            $schema = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw $reader->invalid('#', 'is not JSON: '.$invalid->getMessage());
        }

        if (! is_array($schema) || array_is_list($schema) && $schema !== []) {
            throw $reader->invalid('#', 'is not a JSON object');
        }

        $definitions = [];
        $defs = $schema['$defs'] ?? [];

        if (! is_array($defs) || array_is_list($defs) && $defs !== []) {
            throw $reader->invalid('#/$defs', 'is not an object of definitions');
        }

        foreach ($defs as $key => $definition) {
            $definitions[(string) $key] = $reader->shape($definition, '#/$defs/'.$key);
        }

        return new ShapeDocument($reader->shape($schema, '#'), $definitions);
    }

    private function shape(mixed $node, string $at): JsonShape
    {
        if ($node === true) {
            return new JsonShape(ShapeKind::Any);
        }

        if (! is_array($node) || array_is_list($node) && $node !== []) {
            throw $this->invalid($at, 'is not a schema object');
        }

        foreach (array_keys($node) as $keyword) {
            if (! in_array($keyword, self::STRUCTURAL, true) && ! in_array($keyword, self::NARROWING, true)) {
                throw $this->invalid($at, sprintf('has the keyword "%s", which cms:panel:types has no TypeScript form for', $keyword));
            }
        }

        $description = is_string($node['description'] ?? null) ? $node['description'] : null;

        if (array_key_exists('$ref', $node)) {
            $ref = $node['$ref'];

            if (! is_string($ref) || ! str_starts_with($ref, self::DEFS) || str_contains(substr($ref, strlen(self::DEFS)), '/')) {
                throw $this->invalid($at.'/$ref', 'is not a reference to a definition, #/$defs/<key>');
            }

            return new JsonShape(ShapeKind::Reference, reference: substr($ref, strlen(self::DEFS)), description: $description);
        }

        foreach (['anyOf' => ShapeKind::Union, 'oneOf' => ShapeKind::Union, 'allOf' => ShapeKind::Intersection] as $keyword => $kind) {
            if (array_key_exists($keyword, $node)) {
                $branches = $node[$keyword];

                if (! is_array($branches) || ! array_is_list($branches) || $branches === []) {
                    throw $this->invalid($at.'/'.$keyword, 'is not a list of schemas');
                }

                $members = [];

                foreach ($branches as $index => $branch) {
                    $members[] = $this->shape($branch, $at.'/'.$keyword.'/'.$index);
                }

                return new JsonShape($kind, members: $members, description: $description);
            }
        }

        if (array_key_exists('const', $node)) {
            return new JsonShape(ShapeKind::Literal, literal: $this->literal($node['const'], $at.'/const'), description: $description);
        }

        if (array_key_exists('enum', $node)) {
            $values = $node['enum'];

            if (! is_array($values) || ! array_is_list($values) || $values === []) {
                throw $this->invalid($at.'/enum', 'is not a list of values');
            }

            $members = [];

            foreach ($values as $index => $value) {
                $members[] = new JsonShape(ShapeKind::Literal, literal: $this->literal($value, $at.'/enum/'.$index));
            }

            return count($members) === 1
                ? new JsonShape(ShapeKind::Literal, literal: $members[0]->literal, description: $description)
                : new JsonShape(ShapeKind::Union, members: $members, description: $description);
        }

        $types = $this->types($node, $at);
        $members = array_map(fn (string $type): JsonShape => $this->kind($type, $node, $at), $types);

        if ($members === []) {
            return new JsonShape(ShapeKind::Any, description: $description);
        }

        if (count($members) === 1) {
            $only = $members[0];

            return new JsonShape($only->kind, $only->properties, $only->additional, $only->closed, $only->items, description: $description);
        }

        return new JsonShape(ShapeKind::Union, members: $members, description: $description);
    }

    /**
     * The types the node names, or those its members imply: an object for `properties`.
     *
     * @param  array<array-key, mixed>  $node
     * @return list<string>
     */
    private function types(array $node, string $at): array
    {
        $type = $node['type'] ?? null;

        if ($type === null) {
            return array_key_exists('properties', $node) || array_key_exists('additionalProperties', $node) ? ['object'] : [];
        }

        $types = is_string($type) ? [$type] : $type;

        if (! is_array($types) || ! array_is_list($types) || $types === []) {
            throw $this->invalid($at.'/type', 'is not a type or a list of types');
        }

        $known = [];

        foreach ($types as $each) {
            if (! in_array($each, ['null', 'string', 'integer', 'number', 'boolean', 'array', 'object'], true)) {
                throw $this->invalid($at.'/type', sprintf('names the type %s, which is not a JSON type', is_string($each) ? '"'.$each.'"' : 'that is not a string'));
            }

            $known[$each === 'integer' ? 'number' : $each] = $each === 'integer' ? 'number' : $each;
        }

        return array_values($known);
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private function kind(string $type, array $node, string $at): JsonShape
    {
        return match ($type) {
            'null' => new JsonShape(ShapeKind::Null),
            'string' => new JsonShape(ShapeKind::String),
            'number' => new JsonShape(ShapeKind::Number),
            'boolean' => new JsonShape(ShapeKind::Boolean),
            'array' => new JsonShape(ShapeKind::Array, items: array_key_exists('items', $node) ? $this->shape($node['items'], $at.'/items') : null),
            default => $this->object($node, $at),
        };
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private function object(array $node, string $at): JsonShape
    {
        $required = $node['required'] ?? [];

        if (! is_array($required) || ! array_is_list($required) || ! array_all($required, static fn (mixed $name): bool => is_string($name))) {
            throw $this->invalid($at.'/required', 'is not a list of member names');
        }

        $properties = $node['properties'] ?? [];

        if (! is_array($properties) || array_is_list($properties) && $properties !== []) {
            throw $this->invalid($at.'/properties', 'is not an object of members');
        }

        $members = [];

        foreach ($properties as $name => $property) {
            $members[] = new ShapeProperty((string) $name, $this->shape($property, $at.'/properties/'.$name), in_array((string) $name, $required, true));
        }

        $additional = $node['additionalProperties'] ?? null;

        return new JsonShape(
            ShapeKind::Object,
            properties: $members,
            additional: $additional === null || is_bool($additional) ? null : $this->shape($additional, $at.'/additionalProperties'),
            closed: $additional === false,
        );
    }

    /**
     * The TypeScript text of a literal value.
     */
    private function literal(mixed $value, string $at): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) json_encode($value),
            is_string($value) => "'".str_replace(['\\', "'", "\n", "\r"], ['\\\\', "\\'", '\\n', '\\r'], $value)."'",
            default => throw $this->invalid($at, 'is a literal object or list, which cms:panel:types has no TypeScript form for'),
        };
    }

    private function invalid(string $at, string $problem): GenerationFailed
    {
        return GenerationFailed::because(GenerateErrorCode::SchemaInvalid, sprintf('%s at %s %s.', ucfirst($this->what), $at, $problem));
    }
}
