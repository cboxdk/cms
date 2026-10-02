<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * One contract version of a JSON codec (GUARDRAILS 2.2): the DTO it encodes, with the objects it
 * holds, the class name of the codec, the version, and the lines of the codec's PHPDoc. A codec in
 * a module's source carries a stability attribute, such as #[Experimental], named in $attribute.
 * The codec of a command's contract version names the command and carries its JSON Schema in
 * $command, the codec of a query's contract version names the query, its JSON Schema and the codec
 * of its result in $query, and the codec of a query's result carries the result's JSON Schema in
 * $result.
 */
#[Internal]
final readonly class CodecContract
{
    /**
     * @param  positive-int  $version
     * @param  list<string>  $summary
     * @param  ?class-string  $attribute  the attribute the codec class carries, or null for none
     */
    public function __construct(
        public CodecObject $root,
        public string $codecClass,
        public int $version,
        public array $summary,
        public ?string $attribute = null,
        public ?CodecCommand $command = null,
        public ?CodecQuery $query = null,
        public ?CodecQueryResult $result = null,
    ) {}
}
