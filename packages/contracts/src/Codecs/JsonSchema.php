<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Codecs;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The JSON Schema of one contract version (GUARDRAILS 2.2): the text of a JSON Schema document of
 * draft 2020-12 that describes the JSON form a JsonCodec reads and writes, such as a command's or a
 * query's document. cms:build puts it in the OpenAPI document of the REST surface as it is (PRD
 * 8.8), so a schema that refers to its own definitions does so with `#/$defs/...`, and the OpenAPI
 * document gives it an `$id` of its own, against which those references resolve.
 *
 * The text must be well-formed JSON whose value is an object; a schema is never a boolean here,
 * because every contract version describes a JSON object.
 */
#[Experimental]
final readonly class JsonSchema
{
    /** How deep the schema may nest, as the codecs read documents. */
    public const int DEPTH = 512;

    /**
     * @throws InvalidJsonSchema when the text is not a JSON object
     */
    public function __construct(
        public string $json,
    ) {
        if (! json_validate($json, self::DEPTH)) {
            throw InvalidJsonSchema::malformed(json_last_error_msg());
        }

        if (! str_starts_with(ltrim($json, " \t\n\r"), '{')) {
            throw InvalidJsonSchema::notAnObject();
        }
    }
}
