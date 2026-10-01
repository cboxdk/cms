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
use Cbox\Cms\Core\Routing\Domain\Dto\PathExplanation;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteStep;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\ResolveOutcome;

/*
 * The delivery API's documents, the path explanation and cms:explain's document through their
 * generated codecs (GUARDRAILS 2.2, PRD 8.9, 8.12): delivery.v1.json, delivery-explanation.v1.json,
 * path-explanation.v1.json, explained-path.v1.json and the core's delivery-fragment.v1.json. Each
 * round-trips to the same canonical JSON, validates against its schema with an independent
 * validator, and the codec refuses what the schema or the class refuses. A document of another
 * contract inside one, such as the record or the explanation, is embedded as its own codec wrote it.
 */

const CODEC_RECORD = '{"cms_id":"01936f5e-8a2b-7c3d-9e4f-000000003631","title":"The harbour opens"}';

const CODEC_EXPLANATION = '{"canonical":{"here":false,"placement":"01936f5e-8a2b-7c3d-9e4f-000000003641","url":"https://north.example/nyheder/harbour"},"mount":{"mount":"01936f5e-8a2b-7c3d-9e4f-000000003614","source":"01936f5e-8a2b-7c3d-9e4f-000000003612"},"node":{"kind":"mount","node":"01936f5e-8a2b-7c3d-9e4f-000000003614"},"outcome":"resolved","placement":{"canonical":true,"entry":"01936f5e-8a2b-7c3d-9e4f-000000003631","looked_under":"01936f5e-8a2b-7c3d-9e4f-000000003612","placement":"01936f5e-8a2b-7c3d-9e4f-000000003641","routable":true,"slug":"harbour","type":"01936f5e-8a2b-7c3d-9e4f-000000003621"},"route":{"path":"/national/harbour","rest":"harbour","route":"/national"},"site":{"handle":"south","host":"south.example","locale":"da","locale_published":true,"site":"01936f5e-8a2b-7c3d-9e4f-000000003602"},"visibility":{"at":"2026-03-10T12:00:00.000000Z","decision":"visible","lifecycle":"active","release":"released","stored":"live","valid_until":"2026-03-10T17:00:00.000000Z","window":{"from":"2026-03-10T11:00:00.000000Z","until":"2026-03-10T17:00:00.000000Z"}}}';

const CODEC_PROBLEM = '{"code":"path_not_found","detail":"Nothing is placed at /nyheder/nothing.","errors":[],"instance":null,"retryable":false,"status":404,"title":"Nothing is shown at the path","type":"https://cbox.dk/cms/errors/path_not_found"}';

function codecMeta(?string $canonicalUrl = 'https://north.example/nyheder/harbour'): DeliveryMeta
{
    return new DeliveryMeta($canonicalUrl, 1, new Locale('da'), new TypeName('app:article'));
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

    expect($json)->toBe('{"data":'.CODEC_RECORD.',"meta":{"canonical_url":"https://north.example/nyheder/harbour","contract":1,"locale":"da","type":"app:article"}}')
        ->and(KernelSchema::errors('delivery.v1.json', $json))->toBe([])
        ->and($decoded->data->value)->toBe(CODEC_RECORD)
        ->and($decoded->meta)->toEqual(codecMeta())
        ->and($codec->encode($decoded, ClassificationAccess::Public))->toBe($json);
});

it('refuses a delivery answer whose record is no object or whose meta breaks its rules', function (string $json, string $path): void {
    expect(codecRefusedAt(static fn (string $json): object => new DeliveryCodecV1()->decode($json, ClassificationAccess::Public), $json))->toBe($path)
        ->and(KernelSchema::errors('delivery.v1.json', $json))->not->toBe([]);
})->with([
    'a record that is a list' => ['{"data":[],"meta":{"canonical_url":null,"contract":1,"locale":"da","type":"app:article"}}', 'data'],
    'a record that is a string' => ['{"data":"x","meta":{"canonical_url":null,"contract":1,"locale":"da","type":"app:article"}}', 'data'],
    'another record contract' => ['{"data":{},"meta":{"canonical_url":null,"contract":2,"locale":"da","type":"app:article"}}', 'meta.contract'],
    'a type without its owner' => ['{"data":{},"meta":{"canonical_url":null,"contract":1,"locale":"da","type":"article"}}', 'meta.type'],
    'no meta' => ['{"data":{}}', 'meta'],
]);

it('writes a delivery explanation with the explanation and the problem as their codecs wrote them, valid against delivery-explanation.v1.json', function (): void {
    $codec = new DeliveryExplanationCodecV1;
    $record = $codec->encode(new DeliveryExplanation(new JsonDocument(CODEC_RECORD), new JsonDocument(CODEC_EXPLANATION), codecMeta(null), null, HttpStatus::Ok), ClassificationAccess::Public);
    $problem = $codec->encode(new DeliveryExplanation(null, new JsonDocument(CODEC_EXPLANATION), null, new JsonDocument(CODEC_PROBLEM), HttpStatus::NotFound), ClassificationAccess::Public);

    expect($record)->toBe('{"data":'.CODEC_RECORD.',"explanation":'.CODEC_EXPLANATION.',"meta":{"canonical_url":null,"contract":1,"locale":"da","type":"app:article"},"problem":null,"status":200}')
        ->and($problem)->toBe('{"data":null,"explanation":'.CODEC_EXPLANATION.',"meta":null,"problem":'.CODEC_PROBLEM.',"status":404}')
        ->and(KernelSchema::errors('delivery-explanation.v1.json', $record))->toBe([])
        ->and(KernelSchema::errors('delivery-explanation.v1.json', $problem))->toBe([])
        ->and(KernelSchema::errors('path-explanation.v1.json', CODEC_EXPLANATION))->toBe([])
        ->and(KernelSchema::errors('problem.v1.json', CODEC_PROBLEM))->toBe([])
        ->and($codec->encode($codec->decode($record, ClassificationAccess::Public), ClassificationAccess::Public))->toBe($record)
        ->and($codec->encode($codec->decode($problem, ClassificationAccess::Public), ClassificationAccess::Public))->toBe($problem);
});

it('refuses a delivery explanation that holds both a record and a problem, or neither, or a status that is not one', function (string $json, string $path): void {
    expect(codecRefusedAt(static fn (string $json): object => new DeliveryExplanationCodecV1()->decode($json, ClassificationAccess::Public), $json))->toBe($path);
})->with([
    'a record and a problem' => ['{"data":{},"explanation":{},"meta":{"canonical_url":null,"contract":1,"locale":"da","type":"app:article"},"problem":{},"status":200}', ''],
    'a record without its meta' => ['{"data":{},"explanation":{},"meta":null,"problem":null,"status":200}', ''],
    'neither' => ['{"data":null,"explanation":{},"meta":null,"problem":null,"status":200}', ''],
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
        ->and($unknownJson)->toBe('{"canonical":null,"mount":null,"node":null,"outcome":"unknown_host","placement":null,"route":null,"site":{"handle":null,"host":"nowhere.example","locale":"da","locale_published":false,"site":null},"visibility":null}')
        ->and(KernelSchema::errors('path-explanation.v1.json', $unknownJson))->toBe([])
        ->and($codec->decode($unknownJson, ClassificationAccess::Public))->toEqual($unknown);
});

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
    $json = $codec->encode($stored, ClassificationAccess::Public);

    expect(KernelSchema::errors('delivery-fragment.v1.json', $json, KernelSchema::CORE_DIRECTORY))->toBe([])
        ->and($codec->decode($json, ClassificationAccess::Public))->toEqual($stored)
        ->and(codecRefusedAt(static fn (string $json): object => $codec->decode($json, ClassificationAccess::Public), '{"body":"x","format":"record","stale":true,"status":299}'))->toBe('status')
        ->and(codecRefusedAt(static fn (string $json): object => $codec->decode($json, ClassificationAccess::Public), '{"body":"x","format":"page","stale":true,"status":200}'))->toBe('format');
});
