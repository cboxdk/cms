<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Codecs\JsonSchema;

/**
 * A route of the REST surface with the JSON Schemas of its documents (PRD 8.8), as the OpenAPI
 * document describes it: the schema of what the caller sends, the command's document of a write
 * or the query's of a read, and, for a read, the schema of the result. A write answers with the
 * receipt, whose schema is the kernel's.
 */
#[Experimental]
final readonly class DescribedRoute
{
    public function __construct(
        public RestRoute $route,
        public JsonSchema $input,
        public ?JsonSchema $result = null,
    ) {}
}
