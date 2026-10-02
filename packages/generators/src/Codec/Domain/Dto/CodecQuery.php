<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The query a codec reads (GUARDRAILS 2.1, 2.2, PRD 6.2): the name of its #[Query], without the
 * version, which is the contract's, the text of the JSON Schema of its document, which the codec
 * carries so that every exposed surface describes the query with it (PRD 8.8), and the class name
 * of the codec of its result, generated next to it, which the codec builds the query's QueryCodec
 * with.
 */
#[Internal]
final readonly class CodecQuery
{
    /**
     * @param  string  $name  the query's name, such as path.resolve
     * @param  string  $schema  the JSON Schema of the query's document, pretty-printed JSON
     * @param  string  $resultCodec  the class name of the codec of the query's result, without its namespace
     */
    public function __construct(
        public string $name,
        public string $schema,
        public string $resultCodec,
    ) {}
}
