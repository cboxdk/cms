<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ProblemCodecV1;
use Throwable;

/*
 * The problem details document (RFC 9457, PRD 8.8), problem.v1.json, through its generated codec
 * (GUARDRAILS 2.2): a Problem with field errors round-trips to equal values, its JSON is canonical
 * and validates against the schema with an independent validator, and the codec refuses what the
 * schema or the Problem refuses.
 */

function problemCodec(): ProblemCodecV1
{
    return new ProblemCodecV1;
}

function rejectedProblem(): Problem
{
    return Problem::of(
        ErrorCode::ValidationFailed,
        'Two fields of the command break their rules.',
        [
            new CatalogError(ErrorCode::ValidationFailed, new FieldPath('fields', 'blocks', 2, 'text'), 'The text is longer than 500 characters.'),
            new CatalogError(ErrorCode::VersionConflict, null, 'The entry changed after it was read.'),
        ],
        '/v1/entries/0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02',
    );
}

function problemJson(): string
{
    $title = json_encode(ErrorCode::ValidationFailed->entry()->explanation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    return '{"code":"validation_failed","detail":"Two fields of the command break their rules.","errors":[{"code":"validation_failed","detail":"The text is longer than 500 characters.","field":"fields.blocks[2].text"},{"code":"version_conflict","detail":"The entry changed after it was read.","field":null}],"instance":"/v1/entries/0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02","retryable":false,"status":422,"title":'.$title.',"type":"docs/reference/errors.md#validation_failed"}';
}

it('writes a problem with field errors as canonical JSON that validates against problem.v1.json, and reads it back equal', function (): void {
    $json = problemCodec()->encode(rejectedProblem(), ClassificationAccess::Public);
    $decoded = problemCodec()->decode($json, ClassificationAccess::Public);

    expect($json)->toBe(problemJson())
        ->and(KernelSchema::errors('problem.v1.json', $json))->toBe([])
        ->and($decoded)->toEqual(rejectedProblem())
        ->and($decoded->errors[0]->path?->equals(new FieldPath('fields', 'blocks', 2, 'text')))->toBeTrue()
        ->and(problemCodec()->encode($decoded, ClassificationAccess::Public))->toBe($json)
        ->and(ProblemCodecV1::VERSION)->toBe(1);
});

it('round-trips a problem without errors or an instance, for a code that may be retried', function (): void {
    $problem = Problem::of(ErrorCode::IdempotencyInFlight, 'Another call with the key order-1042 is still running.');
    $json = problemCodec()->encode($problem, ClassificationAccess::Public);

    expect($json)->toContain('"errors":[],"instance":null,"retryable":true,"status":409,')
        ->and(KernelSchema::errors('problem.v1.json', $json))->toBe([])
        ->and(problemCodec()->decode($json, ClassificationAccess::Public))->toEqual($problem);
});

it('refuses a document that breaks problem.v1.json, as the schema does', function (string $from, string $to, ?string $path, string $reason): void {
    $json = str_replace($from, $to, problemJson());

    expect(Failures::described(static fn (): Problem => problemCodec()->decode($json, ClassificationAccess::Public)))->toBe(['json_invalid', $path, $reason])
        ->and(KernelSchema::errors('problem.v1.json', $json))->not->toBe([]);
})->with([
    'a code that is not a code' => ['"code":"validation_failed","detail":"Two', '"code":"Validation Failed","detail":"Two', 'code', 'is not one of '.implode(', ', array_map(static fn (ErrorCode $code): string => $code->value, ErrorCode::cases()))],
    'a status that is a string' => ['"status":422', '"status":"422"', 'status', 'is not an integer'],
    'an empty detail' => ['"detail":"Two fields of the command break their rules."', '"detail":""', 'detail', 'has 0 characters, fewer than the 1 the field requires'],
    'a field that is not a path' => ['fields.blocks[2].text', 'fields..blocks', 'errors[0].field', 'is not a valid id: A field path is a name followed by names after dots and indexes in brackets, such as "blocks[2].text", got "fields..blocks".'],
    'an error without its detail' => ['"detail":"The entry changed after it was read.",', '', 'errors[1].detail', 'is missing, and the field is required'],
    'an unknown member' => ['"retryable":false', '"retryable":false,"trace":"x"', null, 'has the key "trace", which is not a field of the contract'],
]);

it('refuses a problem that does not answer its code as the catalog says, which the schema cannot see', function (string $from, string $to, string $reason): void {
    $json = str_replace($from, $to, problemJson());

    expect(Failures::described(static fn (): Problem => problemCodec()->decode($json, ClassificationAccess::Public)))->toBe(['json_invalid', null, 'breaks a rule of the contract: '.$reason])
        ->and(KernelSchema::errors('problem.v1.json', $json))->toBe([]);
})->with([
    'another status' => ['"status":422', '"status":400', 'The status of a problem with the code validation_failed is 422, as the error catalog says, got 400.'],
    'another type' => ['errors.md#validation_failed', 'errors.md#version_conflict', 'The type of a problem with the code validation_failed is "docs/reference/errors.md#validation_failed", the section of the error reference for the code, got "docs/reference/errors.md#version_conflict".'],
    'retryable when the catalog says it is not' => ['"retryable":false', '"retryable":true', 'A problem with the code validation_failed is not retryable, as the error catalog says.'],
]);

/*
 * Each bound of problem.v1.json, at it and past it: a value at a bound passes the schema's rule
 * and, where the catalog decides the value, meets the catalog's refusal instead.
 */
it('reads the bounds of each rule of problem.v1.json as the schema does', function (string $from, string $to, ?array $refusal): void {
    $json = (string) preg_replace($from, $to, problemJson(), 1);
    $outcome = null;

    try {
        problemCodec()->decode($json, ClassificationAccess::Public);
    } catch (Throwable) {
        $outcome = Failures::described(static fn (): Problem => problemCodec()->decode($json, ClassificationAccess::Public));
    }

    expect($outcome)->toBe($refusal);
})->with([
    'a status below 100' => ['/"status":422/', '"status":99', ['json_invalid', 'status', 'is 99, less than the minimum 100']],
    'the status 100' => ['/"status":422/', '"status":100', ['json_invalid', null, 'breaks a rule of the contract: The status of a problem with the code validation_failed is 422, as the error catalog says, got 100.']],
    'the status 599' => ['/"status":422/', '"status":599', ['json_invalid', null, 'breaks a rule of the contract: The status of a problem with the code validation_failed is 422, as the error catalog says, got 599.']],
    'a status above 599' => ['/"status":422/', '"status":600', ['json_invalid', 'status', 'is 600, more than the maximum 599']],
    'an empty type' => ['/"type":"[^"]*"/', '"type":""', ['json_invalid', 'type', 'has 0 characters, fewer than the 1 the field requires']],
    'a type of one character' => ['/"type":"[^"]*"/', '"type":"x"', ['json_invalid', null, 'breaks a rule of the contract: The type of a problem with the code validation_failed is "docs/reference/errors.md#validation_failed", the section of the error reference for the code, got "x".']],
    'an empty title' => ['/"title":"[^"]*"/', '"title":""', ['json_invalid', 'title', 'has 0 characters, fewer than the 1 the field requires']],
    'a title of one character' => ['/"title":"[^"]*"/', '"title":"x"', null],
    'an empty instance' => ['/"instance":"[^"]*"/', '"instance":""', ['json_invalid', 'instance', 'has 0 characters, fewer than the 1 the field requires']],
    'an instance of one character' => ['/"instance":"[^"]*"/', '"instance":"\/"', null],
    'a detail of one character' => ['/"detail":"Two[^"]*"/', '"detail":"x"', null],
    'an error detail of one character' => ['/"detail":"The text[^"]*"/', '"detail":"x"', null],
    'an empty error detail' => ['/"detail":"The text[^"]*"/', '"detail":""', ['json_invalid', 'errors[0].detail', 'has 0 characters, fewer than the 1 the field requires']],
]);
