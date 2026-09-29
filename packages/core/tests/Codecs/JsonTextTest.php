<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Codecs\Domain\EncodingFailed;
use stdClass;

/*
 * The JSON text of the generated codecs (GUARDRAILS 2.2): decode() reads one JSON object and
 * refuses what json_decode() lets through, a key twice in an object above all (M0-T10), and
 * encode() writes the canonical form.
 */

it('reads a JSON object with objects as stdClass and arrays as lists', function (): void {
    $value = JsonText::decode('{"a":{"b":[1,"x",null,true,{"c":{}}]},"d":[]}');

    expect($value)->toEqual((object) ['a' => (object) ['b' => [1, 'x', null, true, (object) ['c' => new stdClass]]], 'd' => []])
        ->and(json_encode($value))->toBe('{"a":{"b":[1,"x",null,true,{"c":{}}]},"d":[]}');
});

it('refuses a document that is not a JSON object', function (string $json, string $reason): void {
    $failure = Failures::of(static fn (): stdClass => JsonText::decode($json));

    expect($failure->errorCode)->toBe(ErrorCode::JsonMalformed)
        ->and($failure->errorCode->value)->toBe(DecodingFailed::CODE_MALFORMED)
        ->and($failure->path)->toBeNull()
        ->and($failure->reason)->toContain($reason)
        ->and($failure->getMessage())->toStartWith('[json_malformed] ');
})->with([
    'not JSON' => ['{"a":', 'is not well-formed JSON, or nests more than 63 arrays or objects inside one another: Syntax error'],
    'an empty text' => ['', 'is not well-formed JSON'],
    'a trailing comma' => ['{"a":1,}', 'is not well-formed JSON'],
    'text that is not UTF-8' => ["{\"a\":\"\xff\"}", 'is not well-formed JSON'],
    'a list' => ['[]', 'the document is not a JSON object'],
    'a string' => ['"a"', 'the document is not a JSON object'],
    'null' => ['null', 'the document is not a JSON object'],
    'a number' => ['12', 'the document is not a JSON object'],
]);

it('reads 63 objects inside one another and refuses 64', function (): void {
    $nested = static fn (int $levels): string => str_repeat('{"a":', $levels - 1).'{}'.str_repeat('}', $levels - 1);

    expect(JsonText::decode($nested(63)))->toBeInstanceOf(stdClass::class)
        ->and(Failures::described(static fn (): stdClass => JsonText::decode($nested(64)))[2])->toContain('Maximum stack depth exceeded');
});

it('refuses an object with the same key twice, wherever it is', function (string $json, string $key): void {
    $failure = Failures::of(static fn (): stdClass => JsonText::decode($json));

    expect($failure->errorCode)->toBe(ErrorCode::JsonMalformed)
        ->and($failure->path)->toBeNull()
        ->and($failure->reason)->toBe(sprintf('an object has the key "%s" twice', $key));
})->with([
    'at the top' => ['{"a":1,"b":2,"a":3}', 'a'],
    'in a nested object' => ['{"a":{"b":1,"b":2}}', 'b'],
    'in an object in a list' => ['{"a":[{"x":1},{"y":1,"y":2}]}', 'y'],
    'after a nested object' => ['{"a":{"b":1},"c":[],"a":2}', 'a'],
    'spelled with an escape' => ['{"a":1,"a":2}', 'a'],
    'with whitespace around it' => ["{ \"a\" : 1 ,\n \"a\" : 2 }", 'a'],
    'the empty key' => ['{"":1,"":2}', ''],
    'a key with quotes and brackets' => ['{"a\"{[,":1,"a\"{[,":2}', 'a"{[,'],
    'a key that ends in a backslash' => ['{"a\\\\":1,"b":2,"a\\\\":3}', 'a\\'],
    'a key that is an escaped quote' => ['{"\\"":1,"\\"":2}', '"'],
]);

it('accepts the same key in different objects and the same text as keys and values', function (string $json): void {
    expect(JsonText::decode($json))->toBeInstanceOf(stdClass::class);
})->with([
    'sibling objects' => ['{"a":{"k":1},"b":{"k":2}}'],
    'objects in a list' => ['{"a":[{"k":1},{"k":2}]}'],
    'a value equal to a key' => ['{"a":"a","b":"a"}'],
    'strings in a list' => ['{"a":["a","a","b"]}'],
    'an outer key in a nested object' => ['{"a":{"a":{"a":1}}}'],
    'escaped quotes and structure in values' => ['{"a":"\"}{][,:\\\\","b":"\\\\"}'],
    'keys after a closed nested object' => ['{"x":{"a":1},"a":2}'],
    'keys after a closed list' => ['{"x":[{"a":1}],"a":2}'],
]);

it('writes the canonical form: no whitespace, slashes and non-ASCII unescaped, keys in their order', function (): void {
    $value = new stdClass;
    $value->a = 'æ/ø "å"';
    $value->b = [1, true, null, new stdClass];

    expect(JsonText::encode($value))->toBe('{"a":"æ/ø \"å\"","b":[1,true,null,{}]}');
});

it('refuses to write text that is not UTF-8', function (): void {
    $value = new stdClass;
    $value->a = "\xff";

    expect(static fn (): string => JsonText::encode($value))->toThrow(EncodingFailed::class, 'A generated codec cannot encode the DTO: it has no JSON form: Malformed UTF-8');
});
