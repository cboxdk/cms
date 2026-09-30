<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The `options` of a field of an addon's field type, as the blueprint file writes them and after
 * the type's options schema has accepted them (FieldTypeContribution). A value is a string, an integer, a
 * number, a boolean, null, an object of options or a list of those without a list inside it; cms:generate
 * refuses a list inside a list with generate_schema_invalid. Keys are sorted.
 *
 * Each accessor gives the value at the key in the kind it asks for, or null when the options have
 * no such key or hold null there, and throws InvalidFieldTypeOptions when the value is of another
 * kind.
 */
#[Experimental]
final readonly class FieldTypeOptions
{
    /** @var array<string, string|int|float|bool|FieldTypeOptions|list<string|int|float|bool|FieldTypeOptions|null>|null> */
    private array $values;

    /**
     * @param  array<string, string|int|float|bool|FieldTypeOptions|list<string|int|float|bool|FieldTypeOptions|null>|null>  $values
     */
    public function __construct(array $values = [])
    {
        ksort($values, SORT_STRING);
        $this->values = $values;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * The keys of the options, sorted.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(strval(...), array_keys($this->values));
    }

    /**
     * @throws InvalidFieldTypeOptions
     */
    public function string(string $key): ?string
    {
        $value = $this->values[$key] ?? null;

        return $value === null || is_string($value) ? $value : throw InvalidFieldTypeOptions::kind($key, 'a string');
    }

    /**
     * An integer; a number with a zero fraction, such as YAML's `3.0`, is one, as JSON Schema
     * counts it.
     *
     * @throws InvalidFieldTypeOptions
     */
    public function integer(string $key): ?int
    {
        $value = $this->values[$key] ?? null;

        return match (true) {
            $value === null, is_int($value) => $value,
            is_float($value) && floor($value) === $value && abs($value) <= PHP_INT_MAX => (int) $value,
            default => throw InvalidFieldTypeOptions::kind($key, 'an integer'),
        };
    }

    /**
     * @throws InvalidFieldTypeOptions
     */
    public function number(string $key): int|float|null
    {
        $value = $this->values[$key] ?? null;

        return $value === null || is_int($value) || is_float($value) ? $value : throw InvalidFieldTypeOptions::kind($key, 'a number');
    }

    /**
     * @throws InvalidFieldTypeOptions
     */
    public function boolean(string $key): ?bool
    {
        $value = $this->values[$key] ?? null;

        return $value === null || is_bool($value) ? $value : throw InvalidFieldTypeOptions::kind($key, 'a boolean');
    }

    /**
     * @throws InvalidFieldTypeOptions
     */
    public function object(string $key): ?self
    {
        $value = $this->values[$key] ?? null;

        return $value === null || $value instanceof self ? $value : throw InvalidFieldTypeOptions::kind($key, 'an object');
    }

    /**
     * @return ?list<string>
     *
     * @throws InvalidFieldTypeOptions
     */
    public function strings(string $key): ?array
    {
        $list = $this->list($key);

        if ($list === null) {
            return null;
        }

        $strings = array_values(array_filter($list, is_string(...)));

        return count($strings) === count($list) ? $strings : throw InvalidFieldTypeOptions::kind($key, 'a list of strings');
    }

    /**
     * @return ?list<FieldTypeOptions>
     *
     * @throws InvalidFieldTypeOptions
     */
    public function objects(string $key): ?array
    {
        $list = $this->list($key);

        if ($list === null) {
            return null;
        }

        $objects = array_values(array_filter($list, static fn (string|int|float|bool|FieldTypeOptions|null $item): bool => $item instanceof self));

        return count($objects) === count($list) ? $objects : throw InvalidFieldTypeOptions::kind($key, 'a list of objects');
    }

    /**
     * @return ?list<string|int|float|bool|FieldTypeOptions|null>
     *
     * @throws InvalidFieldTypeOptions
     */
    private function list(string $key): ?array
    {
        $value = $this->values[$key] ?? null;

        return $value === null || is_array($value) ? $value : throw InvalidFieldTypeOptions::kind($key, 'a list');
    }
}
