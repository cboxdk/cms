<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The result of a query a codec writes (GUARDRAILS 2.2, PRD 6.2): the name of the query's #[Query],
 * whose result it is, and the text of the result's JSON Schema, which the codec carries so that
 * every exposed surface describes the answer with it (PRD 8.8).
 */
#[Internal]
final readonly class CodecQueryResult
{
    /**
     * @param  string  $query  the query's name, such as path.resolve
     * @param  string  $schema  the JSON Schema of the result's document, pretty-printed JSON
     */
    public function __construct(
        public string $query,
        public string $schema,
    ) {}
}
