<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A class of generated DTO and the JSON object it encodes to (GUARDRAILS 2.2): its short class
 * name, a summary for its PHPDoc, and its properties sorted by JSON key, so the codec writes the
 * keys in sorted order and one value always gives the same bytes.
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
     */
    public function __construct(
        public string $className,
        public array $summary,
        array $properties,
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
