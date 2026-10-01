---
title: Delivery and explanation JSON
weight: 47
description: "The JSON forms of the delivery API's answers, its fragments, the path explanation and cms:explain --json, each a JSON Schema with a generated codec and TypeScript validator."
---

# Delivery and explanation JSON

<!-- extension-point: packages/contracts/resources/schemas/delivery.v1.json -->
<!-- extension-point: packages/contracts/resources/schemas/delivery-explanation.v1.json -->
<!-- extension-point: packages/contracts/resources/schemas/path-explanation.v1.json -->
<!-- extension-point: packages/contracts/resources/schemas/explained-path.v1.json -->
<!-- extension-point: packages/core/resources/schemas/delivery-fragment.v1.json -->

The delivery API's answers and the explanation of a resolution are contracts like the receipt (GUARDRAILS 2.2): each has a JSON Schema, one file per contract version, a codec `composer generate:protocol` writes into `packages/core/src/Codecs/Boundary/Generated`, and a TypeScript type and validator `cms:generate` writes into `protocol/` of the TypeScript directory. No code writes them by hand, and the Arch suite fails on code that does. All of it is `#[Experimental]`.

| Schema | What it is | PHP form | Codec |
|---|---|---|---|
| [`delivery.v1.json`](../../packages/contracts/resources/schemas/delivery.v1.json) | the body of a 200 answer of `GET /v1/resolve`: `data`, the record, and `meta`, its `canonical_url`, `contract`, `locale` and `type` | `Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryDocument` | `DeliveryCodecV1` |
| [`delivery-explanation.v1.json`](../../packages/contracts/resources/schemas/delivery-explanation.v1.json) | the body of an answer with `debug=1`: `data` and `meta`, or `problem`, with `status` and `explanation` | `Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryExplanation` | `DeliveryExplanationCodecV1` |
| [`path-explanation.v1.json`](../../packages/contracts/resources/schemas/path-explanation.v1.json) | why the page at a path looks as it does: the outcome and each step of the resolution | `Cbox\Cms\Core\Routing\Domain\Dto\PathExplanation` | `PathExplanationCodecV1` |
| [`explained-path.v1.json`](../../packages/contracts/resources/schemas/explained-path.v1.json) | what `cms:explain --json` prints: `content_keys`, `explanation` and `read_position` | `Cbox\Cms\Core\Routing\Domain\Dto\ExplainedPath` | `ExplainedPathCodecV1` |
| [`delivery-fragment.v1.json`](../../packages/core/resources/schemas/delivery-fragment.v1.json) | what a fragment holds of an answer: `body`, `format`, `stale` and `status`; it never leaves the server | `Cbox\Cms\Core\Delivery\Domain\Dto\StoredAnswer` | `DeliveryFragmentCodecV1` |

Every key is always present, the keys are sorted and there is no whitespace, and a value that does not apply is `null`. Times are RFC 3339 in UTC with six decimals.

## Documents inside documents

A member that is a document of another contract is embedded as that contract's own codec wrote it, and held in PHP as a `Cbox\Cms\Contracts\Codecs\JsonDocument`, the JSON text of one object. The codec of the document that holds it checks only that it is an object; the codec and validator of its own contract check its keys. So:

- `data` of `delivery.v1.json` and `delivery-explanation.v1.json` is the record in the record contract of the type `meta.type` names, written by that type's generated codec at the public classification access. Validate it with the validator of that type's record contract, such as `validateAppArticleV1`.
- `explanation` of `delivery-explanation.v1.json` and `explained-path.v1.json` is a document of `path-explanation.v1.json`.
- `problem` of `delivery-explanation.v1.json` is a document of [`problem.v1.json`](problem-details.md).

In TypeScript such a member is a `JsonObject`, and its rule is `{ kind: 'document' }`.

## The path explanation

The explanation holds ids, handles, the path and the decisions, never a field of the entry. A step the resolution did not reach is `null`, and `mount` is `null` too for a node that is not a mount. Two values are not in the document, because the document gives them already: the prefixes of the path a route was looked up as, the path itself, each shorter run of its leading segments and `/`, and the rung of the visibility decision, which `decision` names. `cms:explain` prints both without `--json`.

## The codecs are generated

`composer check:generated` fails when a committed codec is not what its schema generates. `decode()` refuses a document with `Cbox\Cms\Core\Codecs\Domain\DecodingFailed`: `json_malformed` for a document that is not a JSON object, and `json_invalid` with the path of the value for a key that is missing or unknown, a value of the wrong kind, and a value its class refuses, such as a delivery explanation that holds both a record and a problem. A fragment the fragment codec does not read is rebuilt, never served.

The example is in the `Unit` suite:

<!-- example: examples/Unit/Protocol/DeliveryJsonTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Codecs\Boundary\Generated\DeliveryCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\PathExplanationCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryDocument;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryMeta;
use Cbox\Cms\Core\Routing\Domain\Dto\PathExplanation;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteStep;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\ResolveOutcome;

// GET /v1/resolve answers a path with the record its type's generated codec wrote, embedded as it
// is, and what the record is; a client reads the answer with the generated codec of
// delivery.v1.json and the record with the codec of its type's record contract. The explanation
// of a resolution, which cms:explain --json and debug=1 show, has a generated codec of its own.

it('answers a path with the record embedded as its codec wrote it, and its meta', function (): void {
    $record = '{"cms_id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a31","title":"The harbour opens"}';
    $answer = new DeliveryDocument(
        new JsonDocument($record),
        new DeliveryMeta('https://north.example/nyheder/harbour', 1, new Locale('da'), new TypeName('app:article')),
    );
    $codec = new DeliveryCodecV1;

    expect($codec->encode($answer, ClassificationAccess::Public))->toBe(
        '{"data":'.$record.',"meta":{"canonical_url":"https://north.example/nyheder/harbour","contract":1,"locale":"da","type":"app:article"}}',
    )
        ->and($codec->decode($codec->encode($answer, ClassificationAccess::Public), ClassificationAccess::Public)->data->value)->toBe($record)
        ->and(static fn (): DeliveryDocument => $codec->decode('{"data":[],"meta":{"canonical_url":null,"contract":1,"locale":"da","type":"app:article"}}', ClassificationAccess::Public))
        ->toThrow(DecodingFailed::class, '[json_invalid] data: is not an object');
});

it('writes the explanation of a resolution that stopped at the host, every step it did not reach null', function (): void {
    $explanation = new PathExplanation(ResolveOutcome::UnknownHost, new SiteStep(new Host('nowhere.example'), new Locale('da'), null, null, false));

    expect(new PathExplanationCodecV1()->encode($explanation, ClassificationAccess::Public))->toBe(
        '{"canonical":null,"mount":null,"node":null,"outcome":"unknown_host","placement":null,"route":null,'
        .'"site":{"handle":null,"host":"nowhere.example","locale":"da","locale_published":false,"site":null},"visibility":null}',
    );
});
```
