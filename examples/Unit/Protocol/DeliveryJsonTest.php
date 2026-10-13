<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\FieldPath;
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
    $record = '{"cms_id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a31","teaser":"Der Hafen","title":"Der Hafen öffnet"}';
    // Asked in de-AT, whose variant has no release, the record is de's, the next locale of the
    // site's fallback chain, and its teaser, left empty in de, came from en after it.
    $answer = new DeliveryDocument(
        new JsonDocument($record),
        new DeliveryMeta('https://alpen.example/news/hafen', 1, true, [FieldPath::fromString('teaser')], new Locale('de-AT'), new Locale('de'), new TypeName('app:notice')),
    );
    $codec = new DeliveryCodecV1;

    expect($codec->encode($answer, ClassificationAccess::Public))->toBe(
        '{"data":'.$record.',"meta":{"canonical_url":"https://alpen.example/news/hafen","contract":1,"fallback":true,"fallback_fields":["teaser"],"locale":"de-AT","resolved_locale":"de","type":"app:notice"}}',
    )
        ->and($codec->decode($codec->encode($answer, ClassificationAccess::Public), ClassificationAccess::Public)->data->value)->toBe($record)
        ->and(static fn (): DeliveryDocument => $codec->decode('{"data":[],"meta":{"canonical_url":null,"contract":1,"fallback":false,"fallback_fields":[],"locale":"da","resolved_locale":"da","type":"app:article"}}', ClassificationAccess::Public))
        ->toThrow(DecodingFailed::class, '[json_invalid] data: is not an object');
});

it('writes the explanation of a resolution that stopped at the host, every step it did not reach null', function (): void {
    $explanation = new PathExplanation(ResolveOutcome::UnknownHost, new SiteStep(new Host('nowhere.example'), new Locale('da'), null, null, false));

    expect(new PathExplanationCodecV1()->encode($explanation, ClassificationAccess::Public))->toBe(
        '{"canonical":null,"fallback":null,"mount":null,"node":null,"outcome":"unknown_host","placement":null,"redirect":null,"route":null,'
        .'"site":{"handle":null,"host":"nowhere.example","locale":"da","locale_published":false,"site":null},"visibility":null}',
    );
});
