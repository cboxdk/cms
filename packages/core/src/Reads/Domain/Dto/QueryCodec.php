<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\Result;
use Cbox\Cms\Core\Reads\Domain\UnknownQuery;

/**
 * The JSON codecs of one version of a query (GUARDRAILS 2.2): what an exposed surface reads a
 * caller's query with and writes the query's result with, and the JSON Schema of each, which
 * cms:build puts in the OpenAPI document of the REST surface (PRD 8.8). The query's name and
 * version are those of its #[Query].
 *
 * A query holds no classified content, so a surface reads it with public classification access;
 * the result is written with the classification access of the read's principal, which the query
 * pipeline gives on the QueryResult.
 */
#[Experimental]
final readonly class QueryCodec
{
    /**
     * @param  JsonCodec<covariant Query>  $query
     * @param  JsonCodec<covariant Result>  $result
     *
     * @throws UnknownQuery when the version is below 1
     */
    public function __construct(
        public CommandName $name,
        public int $version,
        public JsonCodec $query,
        public JsonSchema $querySchema,
        public JsonCodec $result,
        public JsonSchema $resultSchema,
    ) {
        if ($version < 1) {
            throw UnknownQuery::version($name->value, $version);
        }
    }
}
