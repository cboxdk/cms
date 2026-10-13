<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Codecs\Boundary\Generated\DeliveryCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\DeliveryExplanationCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\DeliveryFragmentCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ExplainedPathCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\PathExplanationCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Delivery\Domain\AnswerFormat;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryDocument;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryExplanation;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryMeta;
use Cbox\Cms\Core\Delivery\Domain\Dto\StoredAnswer;
use Cbox\Cms\Core\Routing\Domain\Dto\ExplainedPath;
use Cbox\Cms\Core\Routing\Domain\Dto\FallbackStep;
use Cbox\Cms\Core\Routing\Domain\Dto\PathExplanation;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteStep;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\ResolveOutcome;
use InvalidArgumentException;

/*
 * The delivery API's documents, the path explanation and cms:explain's document through their
 * generated codecs (GUARDRAILS 2.2, PRD 8.9, 8.12): delivery.v1.json, delivery-explanation.v1.json,
 * path-explanation.v1.json, explained-path.v1.json and the core's delivery-fragment.v1.json. Each
 * round-trips to the same canonical JSON, validates against its schema with an independent
 * validator, and the codec refuses what the schema or the class refuses. A document of another
 * contract inside one, such as the record or the explanation, is embedded as its own codec wrote it.
 */

const CODEC_RECORD = '{"cms_id":"01936f5e-8a2b-7c3d-9e4f-000000003631","title":"The harbour opens"}';

const CODEC_EXPLANATION = '{"canonical":{"here":false,"placement":"01936f5e-8a2b-7c3d-9e4f-000000003641","url":"https://north.example/nyheder/harbour"},"fallback":null,"mount":{"mount":"01936f5e-8a2b-7c3d-9e4f-000000003614","source":"01936f5e-8a2b-7c3d-9e4f-000000003612"},"node":{"kind":"mount","node":"01936f5e-8a2b-7c3d-9e4f-000000003614"},"outcome":"resolved","placement":{"canonical":true,"entry":"01936f5e-8a2b-7c3d-9e4f-000000003631","looked_under":"01936f5e-8a2b-7c3d-9e4f-000000003612","placement":"01936f5e-8a2b-7c3d-9e4f-000000003641","routable":true,"slug":"harbour","type":"01936f5e-8a2b-7c3d-9e4f-000000003621"},"redirect":null,"route":{"path":"/national/harbour","rest":"harbour","route":"/national"},"site":{"handle":"south","host":"south.example","locale":"da","locale_published":true,"site":"01936f5e-8a2b-7c3d-9e4f-000000003602"},"visibility":{"at":"2026-03-10T12:00:00.000000Z","decision":"visible","lifecycle":"active","release":"released","stored":"live","valid_until":"2026-03-10T17:00:00.000000Z","window":{"from":"2026-03-10T11:00:00.000000Z","until":"2026-03-10T17:00:00.000000Z"}}}';

const CODEC_REDIRECT = '{"canonical":null,"fallback":null,"mount":{"mount":"01936f5e-8a2b-7c3d-9e4f-000000003614","source":"01936f5e-8a2b-7c3d-9e4f-000000003612"},"node":{"kind":"mount","node":"01936f5e-8a2b-7c3d-9e4f-000000003614"},"outcome":"redirect","placement":{"canonical":true,"entry":"01936f5e-8a2b-7c3d-9e4f-000000003631","looked_under":"01936f5e-8a2b-7c3d-9e4f-000000003612","placement":"01936f5e-8a2b-7c3d-9e4f-000000003641","routable":true,"slug":"harbour","type":"01936f5e-8a2b-7c3d-9e4f-000000003621"},"redirect":{"placement":"01936f5e-8a2b-7c3d-9e4f-000000003642","reason":"own_placement","url":"https://south.example/lokalt/havnen","valid_until":null},"route":{"path":"/national/harbour","rest":"harbour","route":"/national"},"site":{"handle":"south","host":"south.example","locale":"da","locale_published":true,"site":"01936f5e-8a2b-7c3d-9e4f-000000003602"},"visibility":null}';

const CODEC_PROBLEM = '{"code":"path_not_found","detail":"Nothing is placed at /nyheder/nothing.","errors":[],"instance":null,"retryable":false,"status":404,"title":"Nothing is shown at the path","type":"https://cbox.dk/cms/errors/path_not_found"}';

function codecMeta(?string $canonicalUrl = 'https://north.example/nyheder/harbour'): DeliveryMeta
{
    return new DeliveryMeta($canonicalUrl, 1, false, [], new Locale('da'), new Locale('da'), new TypeName('app:article'));
}

/**
 * The codec's refusal of a document: the path it names, '' for the document, or null when it reads it.
 *
 * @param  callable(string): object  $decode
 */
function codecRefusedAt(callable $decode, string $json): ?string
{
    try {
        $decode($json);
    } catch (DecodingFailed $failure) {
        return $failure->path?->toString() ?? '';
    }

    return null;
}

it('writes a delivery answer with the record embedded as its codec wrote it, valid against delivery.v1.json, and reads it back', function (): void {
    $codec = new DeliveryCodecV1;
    $json = $codec->encode(new DeliveryDocument(new JsonDocument(CODEC_RECORD), codecMeta()), ClassificationAccess::Public);
    $decoded = $codec->decode($json, ClassificationAccess::Public);

    expect($json)->toBe('{"data":'.CODEC_RECORD.',"meta":{"canonical_url":"https://north.example/nyheder/harbour","contract":1,"fallback":false,"fallback_fields":[],"locale":"da","resolved_locale":"da","type":"app:article"}}')
        ->and(KernelSchema::errors('delivery.v1.json', $json))->toBe([])
        ->and($decoded->data->value)->toBe(CODEC_RECORD)
        ->and($decoded->meta)->toEqual(codecMeta())
        ->and($codec->encode($decoded, ClassificationAccess::Public))->toBe($json);
});

it('refuses a delivery answer whose record is no object or whose meta breaks its rules', function (string $json, string $path): void {
    expect(codecRefusedAt(static fn (string $json): object => new DeliveryCodecV1()->decode($json, ClassificationAccess::Public), $json))->toBe($path)
        ->and(KernelSchema::errors('delivery.v1.json', $json))->not->toBe([]);
})->with([
    'a record that is a list' => ['{"data":[],"meta":{"canonical_url":null,"contract":1,"fallback":false,"fallback_fields":[],"locale":"da","resolved_locale":"da","type":"app:article"}}', 'data'],
    'a record that is a string' => ['{"data":"x","meta":{"canonical_url":null,"contract":1,"fallback":false,"fallback_fields":[],"locale":"da","resolved_locale":"da","type":"app:article"}}', 'data'],
    'another record contract' => ['{"data":{},"meta":{"canonical_url":null,"contract":2,"locale":"da","type":"app:article"}}', 'meta.contract'],
    'a type without its owner' => ['{"data":{},"meta":{"canonical_url":null,"contract":1,"fallback":false,"fallback_fields":[],"locale":"da","resolved_locale":"da","type":"article"}}', 'meta.type'],
    'no meta' => ['{"data":{}}', 'meta'],
]);

it('writes a delivery explanation with the explanation and the problem as their codecs wrote them, valid against delivery-explanation.v1.json', function (): void {
    $codec = new DeliveryExplanationCodecV1;
    $record = $codec->encode(new DeliveryExplanation(new JsonDocument(CODEC_RECORD), new JsonDocument(CODEC_EXPLANATION), codecMeta(null), null, HttpStatus::Ok), ClassificationAccess::Public);
    $problem = $codec->encode(new DeliveryExplanation(null, new JsonDocument(CODEC_EXPLANATION), null, new JsonDocument(CODEC_PROBLEM), HttpStatus::NotFound), ClassificationAccess::Public);

    expect($record)->toBe('{"data":'.CODEC_RECORD.',"explanation":'.CODEC_EXPLANATION.',"meta":{"canonical_url":null,"contract":1,"fallback":false,"fallback_fields":[],"locale":"da","resolved_locale":"da","type":"app:article"},"problem":null,"status":200}')
        ->and($problem)->toBe('{"data":null,"explanation":'.CODEC_EXPLANATION.',"meta":null,"problem":'.CODEC_PROBLEM.',"status":404}')
        ->and(KernelSchema::errors('delivery-explanation.v1.json', $record))->toBe([])
        ->and(KernelSchema::errors('delivery-explanation.v1.json', $problem))->toBe([])
        ->and(KernelSchema::errors('path-explanation.v1.json', CODEC_EXPLANATION))->toBe([])
        ->and(KernelSchema::errors('problem.v1.json', CODEC_PROBLEM))->toBe([])
        ->and($codec->encode($codec->decode($record, ClassificationAccess::Public), ClassificationAccess::Public))->toBe($record)
        ->and($codec->encode($codec->decode($problem, ClassificationAccess::Public), ClassificationAccess::Public))->toBe($problem);
});

it('writes the explanation of a redirect with the status 301 and neither a record nor a problem, valid against both schemas', function (): void {
    $codec = new DeliveryExplanationCodecV1;
    $redirect = $codec->encode(new DeliveryExplanation(null, new JsonDocument(CODEC_REDIRECT), null, null, HttpStatus::MovedPermanently), ClassificationAccess::Public);

    expect($redirect)->toBe('{"data":null,"explanation":'.CODEC_REDIRECT.',"meta":null,"problem":null,"status":301}')
        ->and(KernelSchema::errors('delivery-explanation.v1.json', $redirect))->toBe([])
        ->and(KernelSchema::errors('path-explanation.v1.json', CODEC_REDIRECT))->toBe([])
        ->and($codec->encode($codec->decode($redirect, ClassificationAccess::Public), ClassificationAccess::Public))->toBe($redirect)
        ->and(new PathExplanationCodecV1()->encode(new PathExplanationCodecV1()->decode(CODEC_REDIRECT, ClassificationAccess::Public), ClassificationAccess::Public))->toBe(CODEC_REDIRECT);
});

it('refuses a delivery explanation that holds both a record and a problem, or neither, or a status that is not one', function (string $json, string $path): void {
    expect(codecRefusedAt(static fn (string $json): object => new DeliveryExplanationCodecV1()->decode($json, ClassificationAccess::Public), $json))->toBe($path);
})->with([
    'a record and a problem' => ['{"data":{},"explanation":{},"meta":{"canonical_url":null,"contract":1,"fallback":false,"fallback_fields":[],"locale":"da","resolved_locale":"da","type":"app:article"},"problem":{},"status":200}', ''],
    'a record without its meta' => ['{"data":{},"explanation":{},"meta":null,"problem":null,"status":200}', ''],
    'neither' => ['{"data":null,"explanation":{},"meta":null,"problem":null,"status":200}', ''],
    'a redirect with a problem' => ['{"data":null,"explanation":{},"meta":null,"problem":{},"status":301}', ''],
    'a redirect with a record' => ['{"data":{},"explanation":{},"meta":{"canonical_url":null,"contract":1,"fallback":false,"fallback_fields":[],"locale":"da","resolved_locale":"da","type":"app:article"},"problem":null,"status":301}', ''],
    'an explanation of null' => ['{"data":null,"explanation":null,"meta":null,"problem":{},"status":404}', 'explanation'],
    'a status the kernel never answers' => ['{"data":null,"explanation":{},"meta":null,"problem":{},"status":418}', 'status'],
]);

it('writes a path explanation valid against path-explanation.v1.json and reads it back to the same steps', function (): void {
    $codec = new PathExplanationCodecV1;
    $decoded = $codec->decode(CODEC_EXPLANATION, ClassificationAccess::Public);
    $unknown = new PathExplanation(ResolveOutcome::UnknownHost, new SiteStep(new Host('Nowhere.Example'), new Locale('da'), null, null, false));
    $unknownJson = $codec->encode($unknown, ClassificationAccess::Public);

    expect($codec->encode($decoded, ClassificationAccess::Public))->toBe(CODEC_EXPLANATION)
        ->and($decoded->route?->candidates)->toBe(['/national/harbour', '/national', '/'])
        ->and($decoded->visibility?->decision->rung())->toBe(11)
        ->and($unknownJson)->toBe('{"canonical":null,"fallback":null,"mount":null,"node":null,"outcome":"unknown_host","placement":null,"redirect":null,"route":null,"site":{"handle":null,"host":"nowhere.example","locale":"da","locale_published":false,"site":null},"visibility":null}')
        ->and(KernelSchema::errors('path-explanation.v1.json', $unknownJson))->toBe([])
        ->and($codec->decode($unknownJson, ClassificationAccess::Public))->toEqual($unknown);
});

it('writes a delivery answer that fell back, with the locale of its variant and the fields that fell back, valid against delivery.v1.json', function (): void {
    $codec = new DeliveryCodecV1;
    $meta = new DeliveryMeta('https://alpen.example/news/hafen', 1, true, [FieldPath::fromString('ext.reviews.summary'), FieldPath::fromString('teaser')], new Locale('de-AT'), new Locale('de'), new TypeName('app:notice'));
    $json = $codec->encode(new DeliveryDocument(new JsonDocument(CODEC_RECORD), $meta), ClassificationAccess::Public);

    expect($json)->toBe('{"data":'.CODEC_RECORD.',"meta":{"canonical_url":"https://alpen.example/news/hafen","contract":1,"fallback":true,"fallback_fields":["ext.reviews.summary","teaser"],"locale":"de-AT","resolved_locale":"de","type":"app:notice"}}')
        ->and(KernelSchema::errors('delivery.v1.json', $json))->toBe([])
        ->and($codec->decode($json, ClassificationAccess::Public)->meta)->toEqual($meta)
        ->and(static fn (): DeliveryMeta => new DeliveryMeta(null, 1, false, [], new Locale('de-AT'), new Locale('de'), new TypeName('app:notice')))
        ->toThrow(InvalidArgumentException::class, 'falls back exactly when the two differ');
});

it('refuses a delivery meta whose fallback keys break their rules', function (string $from, string $to, string $path): void {
    $json = str_replace($from, $to, '{"data":{},"meta":{"canonical_url":null,"contract":1,"fallback":true,"fallback_fields":["teaser"],"locale":"de-AT","resolved_locale":"de","type":"app:notice"}}');

    expect(codecRefusedAt(static fn (string $json): object => new DeliveryCodecV1()->decode($json, ClassificationAccess::Public), $json))->toBe($path)
        ->and(KernelSchema::errors('delivery.v1.json', $json))->not->toBe([]);
})->with([
    'no resolved locale' => [',"resolved_locale":"de"', '', 'meta.resolved_locale'],
    'a resolved locale that is no locale' => ['"resolved_locale":"de"', '"resolved_locale":"deutsch"', 'meta.resolved_locale'],
    'a fallback that is no boolean' => ['"fallback":true', '"fallback":"yes"', 'meta.fallback'],
    'a fallback field that is no path' => ['["teaser"]', '["tea ser"]', 'meta.fallback_fields[0]'],
    'no fallback fields' => [',"fallback_fields":["teaser"]', '', 'meta.fallback_fields'],
]);

it('writes the fallback step of a path explanation valid against path-explanation.v1.json and reads it back', function (): void {
    $codec = new PathExplanationCodecV1;
    $json = str_replace('"fallback":null', '"fallback":{"fallback":true,"fields":["teaser"],"resolved":"de","tried":["de-AT","de","en"]}', CODEC_EXPLANATION);
    $none = str_replace('"fallback":null', '"fallback":{"fallback":false,"fields":[],"resolved":null,"tried":["de-AT"]}', CODEC_EXPLANATION);
    $decoded = $codec->decode($json, ClassificationAccess::Public);

    expect($codec->encode($decoded, ClassificationAccess::Public))->toBe($json)
        ->and(KernelSchema::errors('path-explanation.v1.json', $json))->toBe([])
        ->and(KernelSchema::errors('path-explanation.v1.json', $none))->toBe([])
        ->and($decoded->fallback)->toEqual(new FallbackStep([new Locale('de-AT'), new Locale('de'), new Locale('en')], new Locale('de'), true, [FieldPath::fromString('teaser')]))
        ->and($codec->encode($codec->decode($none, ClassificationAccess::Public), ClassificationAccess::Public))->toBe($none);
});

it('refuses a fallback step that breaks the rules of its class', function (string $step, string $path): void {
    $json = str_replace('"fallback":null', '"fallback":'.$step, CODEC_EXPLANATION);

    expect(codecRefusedAt(static fn (string $json): object => new PathExplanationCodecV1()->decode($json, ClassificationAccess::Public), $json))->toBe($path);
})->with([
    'nothing tried' => ['{"fallback":false,"fields":[],"resolved":null,"tried":[]}', 'fallback.tried'],
    'a resolved locale it did not try' => ['{"fallback":true,"fields":[],"resolved":"fr","tried":["de-AT","de"]}', 'fallback'],
    'a fallback to the locale requested' => ['{"fallback":true,"fields":[],"resolved":"de-AT","tried":["de-AT","de"]}', 'fallback'],
    'fields without a resolved locale' => ['{"fallback":false,"fields":["teaser"],"resolved":null,"tried":["de-AT"]}', 'fallback'],
    'a locale that is no locale' => ['{"fallback":false,"fields":[],"resolved":null,"tried":["austrian"]}', 'fallback.tried[0]'],
]);

it('refuses a path explanation whose steps break the rules of their classes', function (string $from, string $to, string $path): void {
    $json = str_replace($from, $to, CODEC_EXPLANATION);

    expect(codecRefusedAt(static fn (string $json): object => new PathExplanationCodecV1()->decode($json, ClassificationAccess::Public), $json))->toBe($path)
        ->and(KernelSchema::errors('path-explanation.v1.json', $json))->not->toBe([]);
})->with([
    'a path without its leading slash' => ['"path":"/national/harbour"', '"path":"national/harbour"', 'route.path'],
    'a slug with a slash' => ['"slug":"harbour"', '"slug":"har/bour"', 'placement.slug'],
    'a host with a space' => ['"host":"south.example"', '"host":"south example"', 'site.host'],
    'a decision that is not one' => ['"decision":"visible"', '"decision":"shown"', 'visibility.decision'],
    'a window that is no time' => ['"from":"2026-03-10T11:00:00.000000Z"', '"from":"morning"', 'visibility.window.from'],
    'a node kind that is not one' => ['"kind":"mount"', '"kind":"folder"', 'node.kind'],
    'a step it does not have' => ['"outcome":"resolved"', '"outcome":"resolved","rung":11', ''],
    'a redirect step without its URL' => ['"redirect":null', '"redirect":{"placement":null,"reason":"own_placement","valid_until":null}', 'redirect.url'],
    'a redirect reason that is not one' => ['"redirect":null', '"redirect":{"placement":null,"reason":"moved","url":"https://south.example/a","valid_until":null}', 'redirect.reason'],
    'a redirect placement that is not a UUIDv7' => ['"redirect":null', '"redirect":{"placement":"p-1","reason":"own_placement","url":"https://south.example/a","valid_until":null}', 'redirect.placement'],
]);

it('writes cms:explain\'s document with sorted content keys and the explanation as its codec wrote it, valid against explained-path.v1.json', function (): void {
    $codec = new ExplainedPathCodecV1;
    $node = DependencyKey::node(NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003612'));
    $entry = DependencyKey::entry(EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003631'));
    $json = $codec->encode(new ExplainedPath([$node, $entry, $node], new JsonDocument(CODEC_EXPLANATION), new CommitPosition('4827')), ClassificationAccess::Public);

    expect($json)->toBe('{"content_keys":["e-01936f5e-8a2b-7c3d-9e4f-000000003631","n-01936f5e-8a2b-7c3d-9e4f-000000003612"],"explanation":'.CODEC_EXPLANATION.',"read_position":"4827"}')
        ->and(KernelSchema::errors('explained-path.v1.json', $json))->toBe([])
        ->and($codec->encode($codec->decode($json, ClassificationAccess::Public), ClassificationAccess::Public))->toBe($json)
        ->and(codecRefusedAt(static fn (string $json): object => $codec->decode($json, ClassificationAccess::Public), str_replace('"e-01936f5e', '"x-01936f5e', $json)))->toBe('content_keys[0]')
        ->and(codecRefusedAt(static fn (string $json): object => $codec->decode($json, ClassificationAccess::Public), str_replace('"4827"', '"04827"', $json)))->toBe('read_position');
});

it('writes a fragment as delivery-fragment.v1.json and refuses every other form', function (): void {
    $codec = new DeliveryFragmentCodecV1;
    $stored = new StoredAnswer(HttpStatus::NotFound, AnswerFormat::Problem, CODEC_PROBLEM, false);
    $redirect = new StoredAnswer(HttpStatus::MovedPermanently, AnswerFormat::Redirect, '', false, 'https://south.example/lokalt/havnen');
    $json = $codec->encode($stored, ClassificationAccess::Public);
    $redirectJson = $codec->encode($redirect, ClassificationAccess::Public);

    expect(KernelSchema::errors('delivery-fragment.v1.json', $json, KernelSchema::CORE_DIRECTORY))->toBe([])
        ->and($codec->decode($json, ClassificationAccess::Public))->toEqual($stored)
        ->and($redirectJson)->toBe('{"body":"","format":"redirect","location":"https://south.example/lokalt/havnen","stale":false,"status":301}')
        ->and(KernelSchema::errors('delivery-fragment.v1.json', $redirectJson, KernelSchema::CORE_DIRECTORY))->toBe([])
        ->and($codec->decode($redirectJson, ClassificationAccess::Public))->toEqual($redirect)
        ->and(codecRefusedAt(static fn (string $json): object => $codec->decode($json, ClassificationAccess::Public), '{"body":"","format":"redirect","stale":false,"status":301}'))->toBe('location')
        ->and(codecRefusedAt(static fn (string $json): object => $codec->decode($json, ClassificationAccess::Public), '{"body":"x","format":"record","location":null,"stale":true,"status":299}'))->toBe('status')
        ->and(codecRefusedAt(static fn (string $json): object => $codec->decode($json, ClassificationAccess::Public), '{"body":"x","format":"page","location":null,"stale":true,"status":200}'))->toBe('format');
});
