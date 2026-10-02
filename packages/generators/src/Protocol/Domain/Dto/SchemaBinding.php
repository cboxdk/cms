<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Protocol\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Protocol\Domain\ProtocolSchemas;

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
 *
 * The schema is in $directory, below the root of cboxdk/cms. A schema of a command's contract
 * version names the command's class in $command: the class the document is bound to, whose
 * #[Command] gives the name and must give the version, and the codec carries the schema and builds
 * the command's CommandCodec. A schema of a query's contract version names the query's class in
 * $query, the class the document is bound to, whose #[Query] gives the name and must give the
 * version, and the class name of the codec of the query's result in $resultCodec; the codec carries
 * the schema and builds the query's QueryCodec with the result's codec. The schema of a query's
 * result names the query's class in $resultOf, whose #[Query] must give the version, and its codec
 * carries the schema.
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
     * @param  string  $directory  the schema's directory, relative to the root of cboxdk/cms
     * @param  ?string  $command  the command's class, the object at `#`, when the schema is a command's contract version
     * @param  ?string  $query  the query's class, the object at `#`, when the schema is a query's contract version
     * @param  ?string  $resultCodec  the class name of the codec of the query's result, without its namespace, with $query
     * @param  ?string  $resultOf  the query's class, when the schema is the result of a query's contract version
     */
    public function __construct(
        public string $schema,
        public string $codecClass,
        public int $version,
        public array $objects,
        public array $values = [],
        public array $names = [],
        public string $directory = ProtocolSchemas::SCHEMA_DIRECTORY,
        public ?string $command = null,
        public ?string $query = null,
        public ?string $resultCodec = null,
        public ?string $resultOf = null,
    ) {}

    /**
     * The schema's path, relative to the root of cboxdk/cms.
     */
    public function path(): string
    {
        return $this->directory.'/'.$this->schema;
    }
}
