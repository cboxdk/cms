<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Protocol\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * How one kernel JSON Schema maps to the PHP classes of the contracts (GUARDRAILS 2.2), so its
 * codec reads and writes those classes and no DTO of its own. The schema says which keys there are,
 * which are required or nullable, their defaults and their rules; the binding says only which class
 * each object and each value that is not plain JSON is.
 *
 * Every place is a JSON pointer into the schema, written from `#`: an object by the pointer of its
 * definition, such as `#` for the document and `#/$defs/projection_status`, and a value and a
 * property by the pointer of the place that uses it, such as `#/properties/outcome` or
 * `#/properties/on_behalf_of/items`. A property's PHP name is its key in camelCase unless $names
 * gives another.
 */
#[Internal]
final readonly class SchemaBinding
{
    /**
     * @param  string  $schema  the schema's file name, such as receipt.v1.json
     * @param  string  $codecClass  the codec's class name without its namespace, such as ReceiptCodecV1
     * @param  positive-int  $version  the contract version, the number in the file name
     * @param  array<string, string>  $objects  the class of each object, by the pointer of its definition; the reader refuses one that does not exist
     * @param  array<string, ValueBinding>  $values  the class of each bound value, by the pointer of its place
     * @param  array<string, string>  $names  a property's PHP name, by the pointer of the property, where it is not the key in camelCase
     */
    public function __construct(
        public string $schema,
        public string $codecClass,
        public int $version,
        public array $objects,
        public array $values = [],
        public array $names = [],
    ) {}
}
