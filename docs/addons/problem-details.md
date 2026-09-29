---
title: Problem details
weight: 47
description: "The problem details document (RFC 9457) a surface answers an error with, problem.v1.json: the catalog code with its type, title, status and retryable, the cause, and a field error per reason of a rejected write."
---

# Problem details

<!-- extension-point: packages/contracts/resources/schemas/problem.v1.json -->

A surface that speaks HTTP answers a call that ends with a code of the [error catalog](errors.md) with a problem details document (RFC 9457, PRD 8.8), sent as `application/problem+json` with the status of the response. Its PHP form is `Cbox\Cms\Contracts\Errors\Problem`, and its JSON form is contract version 1, described by the JSON Schema [`problem.v1.json`](../../packages/contracts/resources/schemas/problem.v1.json) and written and read by the generated codec `Cbox\Cms\Core\Codecs\Boundary\Generated\ProblemCodecV1`. All of it is `#[Experimental]`.

## The document

Every key is always present, sorted, without whitespace. The first five are the members of RFC 9457; `code`, `retryable` and `errors` extend it.

| Key | What it holds |
|---|---|
| `type` | the section of the [error reference](../reference/errors.md) for the code, `docs/reference/errors.md#<code>`, a URI reference |
| `title` | the explanation of the code's catalog entry: what it means and what to do |
| `status` | the HTTP status the catalog gives the code |
| `detail` | the concrete cause of this occurrence |
| `instance` | a URI reference that names the occurrence, or `null` |
| `code` | the catalog code, such as `validation_failed` |
| `retryable` | whether the same call may succeed later, as the catalog says |
| `errors` | the reasons a write was rejected, in order: each a `code`, a `detail` and a `field`, the path of the input such as `fields.blocks[2].text`, or `null` when the reason is about the command as a whole |

`Problem::of()` builds the document from a code: the type, title, status and retryable come from its entry, and the surface gives the cause, the reasons and the instance. The constructor refuses a type, a status or a retryable that is not the catalog's, so no surface answers a code otherwise than the catalog says. A field error is a `Cbox\Cms\Contracts\Results\CatalogError`, the same type a rejected `WriteResult` carries, and its path is a `FieldPath`, written by `toString()` and read by `FieldPath::fromString()`.

## The codec is generated

`composer generate:protocol` writes the codec from the schema, bound to `Problem`, `CatalogError`, `ErrorCode` and `FieldPath`, so no code serialises a problem by hand (GUARDRAILS 2.2); `composer check:generated` and the Arch suite hold it to that. `decode()` refuses a document with `DecodingFailed`, `json_invalid` with the path of the value, for a code that is not in the catalog, a field that is not a path, a status or a type that is not the catalog's, and anything the schema refuses.

The example is in the `Unit` suite:

<!-- example: examples/Unit/Protocol/ProblemDetailsTest.php -->
```php
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
```
