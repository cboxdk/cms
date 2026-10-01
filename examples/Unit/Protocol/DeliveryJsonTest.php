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
