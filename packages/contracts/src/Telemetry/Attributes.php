<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Telemetry;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The attributes of a span or a metric, sorted by name, each name once.
 */
#[Experimental]
final readonly class Attributes
{
    /** @var list<Attribute> sorted by name */
    public array $attributes;

    /**
     * @throws InvalidTelemetry when a name is given twice
     */
    public function __construct(Attribute ...$attributes)
    {
        $byName = [];

        foreach ($attributes as $attribute) {
            if (isset($byName[$attribute->name->value])) {
                throw InvalidTelemetry::duplicateAttribute($attribute->name->value);
            }

            $byName[$attribute->name->value] = $attribute;
        }

        ksort($byName, SORT_STRING);
        $this->attributes = array_values($byName);
    }

    /**
     * The value of the attribute with this name, or null when there is none.
     */
    public function get(string $name): string|int|float|bool|null
    {
        foreach ($this->attributes as $attribute) {
            if ($attribute->name->value === $name) {
                return $attribute->value;
            }
        }

        return null;
    }

    /**
     * The attributes' names, sorted.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (Attribute $attribute): string => $attribute->name->value, $this->attributes);
    }

    /**
     * Whether both hold the same names with the same values of the same types.
     */
    public function equals(self $other): bool
    {
        return count($this->attributes) === count($other->attributes)
            && array_all($this->attributes, static fn (Attribute $attribute, int $index): bool => $attribute->name->equals($other->attributes[$index]->name)
                && $attribute->value === $other->attributes[$index]->value);
    }
}
