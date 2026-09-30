<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Codecs;

use Cbox\Cms\Contracts\Codecs\InvalidJsonSchema;
use Cbox\Cms\Contracts\Codecs\JsonSchema;

/*
 * The JSON Schema of a contract version (GUARDRAILS 2.2), which cms:build puts in the OpenAPI
 * document of the REST surface: well-formed JSON whose value is an object.
 */

it('holds the text of a JSON object as it is given', function (string $json): void {
    expect(new JsonSchema($json)->json)->toBe($json);
})->with([
    'an empty object' => ['{}'],
    'a schema' => ['{"type":"object","properties":{"title":{"type":"string"}}}'],
    'an object after whitespace' => [" \n\t{\"type\": \"object\"}"],
]);

it('refuses text that is not well-formed JSON, with the reason', function (): void {
    expect(static fn (): JsonSchema => new JsonSchema('{"type":'))
        ->toThrow(InvalidJsonSchema::class, 'A JSON Schema must be well-formed JSON, and this text is not: Syntax error.');
});

it('refuses JSON whose value is not an object', function (string $json): void {
    expect(static fn (): JsonSchema => new JsonSchema($json))
        ->toThrow(InvalidJsonSchema::class, 'A JSON Schema of a contract version must be a JSON object, such as {"type": "object"}.');
})->with([
    'true' => ['true'],
    'a list' => ['[{"type":"object"}]'],
    'a string' => ['"{}"'],
]);

it('refuses a schema nested deeper than the codecs read', function (): void {
    $deep = str_repeat('{"a":', JsonSchema::DEPTH).'1'.str_repeat('}', JsonSchema::DEPTH);

    expect(static fn (): JsonSchema => new JsonSchema($deep))
        ->toThrow(InvalidJsonSchema::class, 'Maximum stack depth exceeded');
});
