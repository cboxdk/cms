<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Registry\Domain\JsonKind;

/**
 * What cms:build knows of one place in a contract's JSON Schema (PRD 13.4): the kinds of value it
 * takes, every kind for a schema that does not narrow them, the members an object declares with
 * whether each is required, the schema of every other member, whether the object takes no
 * other member (`additionalProperties: false`), and the schema of an array's items. The panel's checks walk it to find whether a path or
 * a JSON pointer exists and what it holds.
 */
#[Experimental]
final readonly class SchemaNode
{
    /** @var list<JsonKind> */
    public array $kinds;

    /** @var array<string, SchemaNode> */
    public array $properties;

    /** @var list<string> */
    public array $required;

    /**
     * @param  list<JsonKind>  $kinds  each once
     * @param  array<string, SchemaNode>  $properties
     * @param  list<string>  $required
     */
    public function __construct(
        array $kinds,
        array $properties = [],
        array $required = [],
        public ?SchemaNode $additional = null,
        public ?SchemaNode $items = null,
        public bool $closed = false,
    ) {
        $byValue = [];

        foreach ($kinds as $kind) {
            $byValue[$kind->value] = $kind;
        }

        ksort($byValue, SORT_STRING);
        ksort($properties, SORT_STRING);
        sort($required, SORT_STRING);

        $this->kinds = array_values($byValue);
        $this->properties = $properties;
        $this->required = $required;
    }

    /**
     * A schema that takes any value: every kind, any member and any item.
     */
    public static function any(): self
    {
        return new self(JsonKind::cases());
    }

    /**
     * The schema of a member of the object, or null when the object neither declares nor takes it.
     */
    public function member(string $name): ?self
    {
        if (! in_array(JsonKind::Object, $this->kinds, true)) {
            return null;
        }

        if (array_key_exists($name, $this->properties)) {
            return $this->properties[$name];
        }

        return $this->additional ?? ($this->closed ? null : self::any());
    }

    /**
     * The schema of an item of the array, or null when it is no array.
     */
    public function item(): ?self
    {
        if (! in_array(JsonKind::Array, $this->kinds, true)) {
            return null;
        }

        return $this->items ?? self::any();
    }

    /**
     * The schema at the path below this one, or null when the schema does not have it. A segment
     * that is a list index or the key of one item of a list goes to the schema of the list's
     * items, which every item of a list shares.
     */
    public function at(FieldPath $path): ?self
    {
        $node = $this;

        foreach ($path->segments as $segment) {
            $node = is_string($segment) ? $node->member($segment) : $node->item();

            if (! $node instanceof self) {
                return null;
            }
        }

        return $node;
    }

    /**
     * The schema at a JSON pointer (RFC 6901), or null when the schema does not have it.
     */
    public function pointer(string $pointer): ?self
    {
        $node = $this;

        foreach (array_slice(explode('/', $pointer), 1) as $token) {
            $token = str_replace(['~1', '~0'], ['/', '~'], $token);
            $node = ctype_digit($token) && in_array(JsonKind::Array, $node->kinds, true) && ! in_array(JsonKind::Object, $node->kinds, true)
                ? $node->item()
                : $node->member($token);

            if (! $node instanceof self) {
                return null;
            }
        }

        return $node;
    }

    /**
     * Whether every value this schema takes is a kind the other takes.
     */
    public function fitsInto(self $other): bool
    {
        return array_all($this->kinds, fn ($kind): bool => array_any($other->kinds, static fn (JsonKind $target): bool => $kind->fits($target)));
    }

    /**
     * The kinds, such as "string|null".
     */
    public function describe(): string
    {
        return implode('|', array_map(static fn (JsonKind $kind): string => $kind->value, $this->kinds));
    }
}
