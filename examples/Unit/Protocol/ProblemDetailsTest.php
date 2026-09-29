<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ProblemCodecV1;

// A REST surface answers a rejected write with problem details (RFC 9457): the catalog entry of
// the code gives the type, the title, the status and whether to retry, and each reason of the
// rejection is a field error at the input it is about.

it('answers a rejected write with problem details and a field error per reason', function (): void {
    $problem = Problem::of(
        ErrorCode::ValidationFailed,
        'One field breaks its rule.',
        [new CatalogError(ErrorCode::ValidationFailed, new FieldPath('fields', 'blocks', 2, 'text'), 'The text is longer than 500 characters.')],
    );
    $json = new ProblemCodecV1()->encode($problem, ClassificationAccess::Public);
    $body = json_decode($json, true, 16, JSON_THROW_ON_ERROR);

    expect($body)->toBe([
        'code' => 'validation_failed',
        'detail' => 'One field breaks its rule.',
        'errors' => [['code' => 'validation_failed', 'detail' => 'The text is longer than 500 characters.', 'field' => 'fields.blocks[2].text']],
        'instance' => null,
        'retryable' => false,
        'status' => 422,
        'title' => ErrorCode::ValidationFailed->entry()->explanation,
        'type' => 'docs/reference/errors.md#validation_failed',
    ]);
});

it('reads the problem back, so a PHP client finds the field of each error', function (): void {
    $codec = new ProblemCodecV1;
    $problem = $codec->decode(
        $codec->encode(Problem::of(ErrorCode::IdempotencyInFlight, 'Another call with the key order-1042 is still running.'), ClassificationAccess::Public),
        ClassificationAccess::Public,
    );

    expect($problem->status)->toBe(409)
        ->and($problem->retryable)->toBeTrue()
        ->and($problem->errors)->toBe([])
        ->and(FieldPath::fromString('fields.blocks[2].text')->segments)->toBe(['fields', 'blocks', 2, 'text']);
});
