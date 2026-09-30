<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The OpenAPI 3.1 document of the REST surface (GUARDRAILS 2.1, PRD 8.8), as cms:build writes it to
 * bootstrap/cache/cms/openapi.json: its JSON text, pretty-printed with every object's keys sorted
 * and a final newline, so the same registry and codecs always give the same bytes.
 */
#[Experimental]
final readonly class OpenApiDocument
{
    public function __construct(
        public string $json,
    ) {}
}
