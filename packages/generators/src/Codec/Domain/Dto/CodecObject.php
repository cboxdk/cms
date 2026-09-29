<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A class of generated DTO and the JSON object it encodes to (GUARDRAILS 2.2): its short class
 * name, a summary for its PHPDoc, and its properties sorted by JSON key, so the codec writes the
 * keys in sorted order and one value always gives the same bytes.
 *
 * An object bound to a class that exists, such as a class of the contracts, names it in $class: no
 * DTO is generated for it, and the codec builds it with `new` and its constructor's named
 * arguments, one per property in the constructor's order, turning the constructor's refusal into
 * DecodingFailed.
 */
#[Internal]
final readonly class CodecObject
{
    /** @var list<CodecProperty> sorted by key */
    public array $properties;

    /**
     * @param  string  $className  the class name without its namespace
     * @param  list<string>  $summary  the lines of the class's PHPDoc
     * @param  list<CodecProperty>  $properties
     * @param  ?class-string  $class  the class the object is bound to, whose short name is $className, or null for a generated DTO
     * @param  list<string>  $arguments  the names of the bound class's constructor arguments in their order, in which the codec passes them
     */
    public function __construct(
        public string $className,
        public array $summary,
        array $properties,
        public ?string $class = null,
        public array $arguments = [],
    ) {
        usort($properties, static fn (CodecProperty $a, CodecProperty $b): int => strcmp($a->key, $b->key));
        $this->properties = $properties;
    }

    /**
     * Whether a property of this object, or of an object it holds once, is withheld above some
     * classification access, so the class has visibleTo().
     */
    public function classified(): bool
    {
        return array_any($this->properties, fn (CodecProperty $property): bool => $property->withheld() || ($property->value->object instanceof self && $property->value->object->classified()));
    }

    /**
     * The properties in the order the codec passes them to the constructor: the bound class's
     * order of its arguments, or the order of the keys for a generated DTO.
     *
     * @return list<CodecProperty>
     */
    public function constructorOrder(): array
    {
        $properties = $this->properties;
        $position = array_flip($this->arguments);
        usort($properties, static fn (CodecProperty $a, CodecProperty $b): int => ($position[$a->name] ?? PHP_INT_MAX) <=> ($position[$b->name] ?? PHP_INT_MAX));

        return $properties;
    }

    /**
     * This object and every object it holds, depth first, each once.
     *
     * @return list<self>
     */
    public function objects(): array
    {
        $objects = [$this];

        foreach ($this->properties as $property) {
            $value = $property->value;

            while ($value->item instanceof CodecValue) {
                $value = $value->item;
            }

            if ($value->object instanceof self) {
                array_push($objects, ...$value->object->objects());
            }
        }

        return $objects;
    }
}
