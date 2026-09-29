<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain\TypeScript;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * An object literal, whose properties keep their order.
 */
#[Internal]
final readonly class ObjectLiteral implements Literal
{
    /**
     * @param  list<Property>  $properties
     */
    public function __construct(public array $properties) {}

    public function flat(): string
    {
        if ($this->properties === []) {
            return '{}';
        }

        return '{ '.implode(', ', array_map(static fn (Property $property): string => $property->key().': '.$property->value->flat(), $this->properties)).' }';
    }
}
