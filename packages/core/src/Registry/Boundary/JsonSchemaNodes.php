<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Registry\Domain\Dto\SchemaNode;
use Cbox\Cms\Core\Registry\Domain\JsonKind;
use JsonException;

/**
 * Reads a JSON Schema of draft 2020-12, as the kernel's contracts write them, into the SchemaNode
 * the panel's build checks walk (PRD 13.4): `type`, `enum` and `const` give the kinds; `properties`,
 * `required` and `additionalProperties` the members; `items` an array's items; `anyOf` and
 * `oneOf` the union of their branches; `allOf` the first branch that narrows; and `$ref` to
 * `#/$defs/...` the definition it names. A keyword it does not know does not narrow, and a schema
 * that refers to itself is read to a fixed depth, below which it takes any value.
 */
#[Internal]
final readonly class JsonSchemaNodes
{
    /** How deep the reader follows nested schemas and references. */
    public const int DEPTH = 24;

    /**
     * @throws JsonException when the text is not JSON
     */
    public static function read(string $json): SchemaNode
    {
        $schema = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($schema) ? self::node($schema, $schema, 0) : SchemaNode::any();
    }

    /**
     * @param  array<mixed>  $schema
     * @param  array<mixed>  $root
     */
    private static function node(array $schema, array $root, int $depth): SchemaNode
    {
        if ($depth > self::DEPTH) {
            return SchemaNode::any();
        }

        $ref = $schema['$ref'] ?? null;

        if (is_string($ref)) {
            $target = self::definition($ref, $root);

            return $target === null ? SchemaNode::any() : self::node($target, $root, $depth + 1);
        }

        foreach (['anyOf', 'oneOf'] as $keyword) {
            $branches = $schema[$keyword] ?? null;

            if (is_array($branches) && $branches !== []) {
                return self::union(array_map(
                    static fn (mixed $branch): SchemaNode => is_array($branch) ? self::node($branch, $root, $depth + 1) : SchemaNode::any(),
                    array_values($branches),
                ));
            }
        }

        $all = $schema['allOf'] ?? null;

        if (is_array($all) && is_array($all[0] ?? null) && ! array_key_exists('type', $schema) && ! array_key_exists('properties', $schema)) {
            return self::node($all[0], $root, $depth + 1);
        }

        $properties = [];

        if (is_array($schema['properties'] ?? null)) {
            foreach ($schema['properties'] as $name => $property) {
                $properties[(string) $name] = is_array($property) ? self::node($property, $root, $depth + 1) : SchemaNode::any();
            }
        }

        $required = [];

        if (is_array($schema['required'] ?? null)) {
            foreach ($schema['required'] as $name) {
                if (is_string($name)) {
                    $required[] = $name;
                }
            }
        }

        $additional = $schema['additionalProperties'] ?? null;
        $items = $schema['items'] ?? null;

        return new SchemaNode(
            self::kinds($schema),
            $properties,
            $required,
            is_array($additional) ? self::node($additional, $root, $depth + 1) : null,
            is_array($items) ? self::node($items, $root, $depth + 1) : null,
            $additional === false,
        );
    }

    /**
     * @param  array<mixed>  $schema
     * @return list<JsonKind>
     */
    private static function kinds(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if (is_string($type) || is_array($type)) {
            $kinds = [];

            foreach ((array) $type as $name) {
                $kind = is_string($name) ? JsonKind::tryFrom($name) : null;

                if ($kind instanceof JsonKind) {
                    $kinds[] = $kind;
                }
            }

            return $kinds === [] ? JsonKind::cases() : $kinds;
        }

        $values = array_key_exists('const', $schema) ? [$schema['const']] : ($schema['enum'] ?? null);

        if (is_array($values) && $values !== []) {
            return array_map(self::kindOf(...), array_values($values));
        }

        return array_key_exists('properties', $schema) ? [JsonKind::Object] : JsonKind::cases();
    }

    private static function kindOf(mixed $value): JsonKind
    {
        return match (true) {
            $value === null => JsonKind::Null,
            is_bool($value) => JsonKind::Boolean,
            is_int($value) => JsonKind::Integer,
            is_float($value) => JsonKind::Number,
            is_string($value) => JsonKind::String,
            is_array($value) && array_is_list($value) => JsonKind::Array,
            default => JsonKind::Object,
        };
    }

    /**
     * @param  list<SchemaNode>  $branches
     */
    private static function union(array $branches): SchemaNode
    {
        $kinds = [];
        $properties = [];
        $additional = null;
        $items = null;
        $closed = true;
        $required = null;

        foreach ($branches as $branch) {
            $kinds = [...$kinds, ...$branch->kinds];
            $properties = [...$properties, ...$branch->properties];
            $additional ??= $branch->additional;
            $items ??= $branch->items;

            if (in_array(JsonKind::Object, $branch->kinds, true)) {
                $closed = $closed && $branch->closed;
                $required = $required === null ? $branch->required : array_values(array_intersect($required, $branch->required));
            }
        }

        return new SchemaNode($kinds, $properties, $required ?? [], $additional, $items, $closed && $required !== null);
    }

    /**
     * The definition a reference within the document names, `#/$defs/<name>` or another JSON pointer.
     *
     * @param  array<mixed>  $root
     * @return array<mixed>|null
     */
    private static function definition(string $ref, array $root): ?array
    {
        if (! str_starts_with($ref, '#')) {
            return null;
        }

        $node = $root;

        foreach (array_slice(explode('/', substr($ref, 1)), 1) as $token) {
            $token = str_replace(['~1', '~0'], ['/', '~'], $token);

            if (! is_array($node) || ! array_key_exists($token, $node)) {
                return null;
            }

            $node = $node[$token];
        }

        return is_array($node) ? $node : null;
    }
}
