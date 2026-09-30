<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Delivery;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ProblemCodecV1;
use Cbox\Cms\Core\Delivery\Adapter\JsonDeliveryDocuments;
use Cbox\Cms\Core\Delivery\Domain\AnswerFormat;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryAnswer;
use Cbox\Cms\Core\Delivery\Domain\Dto\StoredAnswer;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Routing\Boundary\PathExplanationJson;
use Cbox\Cms\Core\Routing\Domain\Dto\CanonicalStep;
use Cbox\Cms\Core\Routing\Domain\Dto\MountStep;
use Cbox\Cms\Core\Routing\Domain\Dto\NodeStep;
use Cbox\Cms\Core\Routing\Domain\Dto\PathExplanation;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementStep;
use Cbox\Cms\Core\Routing\Domain\Dto\RouteStep;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteStep;
use Cbox\Cms\Core\Routing\Domain\Dto\VisibilityStep;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\ReleaseState;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Routing\Domain\ResolveOutcome;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Routing\Domain\VisibilityDecision;
use DateTimeImmutable;

/*
 * The delivery API's documents as JSON (PRD 8.8, 8.9): the record with its meta, byte for byte with
 * the record spliced in as the codec wrote it; the problem as ProblemCodecV1 writes it; the
 * explanation with every step, as PathExplanationJson encodes it; and the stored form of an answer in a fragment.
 */

const DOCUMENT_RECORD = '{"cms_id":"01936f5e-8a2b-7c3d-9e4f-000000003631","title":"The harbour opens"}';

function documentedExplanation(): PathExplanation
{
    return new PathExplanation(
        ResolveOutcome::Resolved,
        new SiteStep(new Host('south.example'), new Locale('da'), new SiteHandle('south'), SiteId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003602'), true),
        new RouteStep(new RequestPath('/national/harbour'), '/national', 'harbour'),
        new NodeStep(NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003614'), NodeKind::Mount),
        new MountStep(NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003614'), NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003612')),
        new PlacementStep(NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003612'), new Slug('harbour'), PlacementId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003641'), EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003631'), TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003621'), true, true),
        new VisibilityStep(VisibilityDecision::Visible, new DateTimeImmutable('2026-03-10T13:00:00+01:00'), EntryLifecycle::Active, ReleaseState::Released, Visibility::Live, new TimeWindow(new DateTimeImmutable('2026-03-10T11:00:00Z'), new DateTimeImmutable('2026-03-10T17:00:00Z')), new DateTimeImmutable('2026-03-10T17:00:00Z')),
        new CanonicalStep(PlacementId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003641'), 'https://north.example/nyheder/harbour', false),
    );
}

it('writes a record as the record as its codec wrote it, with the meta, keys sorted', function (): void {
    $documents = new JsonDeliveryDocuments;

    expect($documents->body(new DeliveryAnswer(HttpStatus::Ok, DOCUMENT_RECORD, new TypeName('app:article'), new Locale('da'), 'https://north.example/nyheder/harbour')))
        ->toBe('{"data":'.DOCUMENT_RECORD.',"meta":{"canonical_url":"https://north.example/nyheder/harbour","contract":1,"locale":"da","type":"app:article"}}')
        ->and($documents->body(new DeliveryAnswer(HttpStatus::Ok, DOCUMENT_RECORD, new TypeName('app:article'), new Locale('da'))))
        ->toBe('{"data":'.DOCUMENT_RECORD.',"meta":{"canonical_url":null,"contract":1,"locale":"da","type":"app:article"}}');
});

it('writes a problem as the generated problem codec writes it', function (): void {
    $problem = Problem::of(ErrorCode::PathGone, 'What is placed at /nyheder/harbour in da at north.example is not shown: placement_withdrawn.');

    expect(new JsonDeliveryDocuments()->body(DeliveryAnswer::problem($problem)))->toBe(new ProblemCodecV1()->encode($problem, ClassificationAccess::Public));
});

it('writes an explanation with every step, ids in their canonical form and instants in UTC', function (): void {
    $body = new JsonDeliveryDocuments()->body(new DeliveryAnswer(HttpStatus::Ok, DOCUMENT_RECORD, new TypeName('app:article'), new Locale('da'), 'https://north.example/nyheder/harbour', explanation: documentedExplanation()));

    expect($body)->toBe('{"data":'.DOCUMENT_RECORD
        .',"explanation":{"canonical":{"here":false,"placement":"01936f5e-8a2b-7c3d-9e4f-000000003641","url":"https://north.example/nyheder/harbour"}'
        .',"mount":{"mount":"01936f5e-8a2b-7c3d-9e4f-000000003614","source":"01936f5e-8a2b-7c3d-9e4f-000000003612"}'
        .',"node":{"kind":"mount","node":"01936f5e-8a2b-7c3d-9e4f-000000003614"}'
        .',"outcome":"resolved"'
        .',"placement":{"canonical":true,"entry":"01936f5e-8a2b-7c3d-9e4f-000000003631","looked_under":"01936f5e-8a2b-7c3d-9e4f-000000003612","placement":"01936f5e-8a2b-7c3d-9e4f-000000003641","routable":true,"slug":"harbour","type":"01936f5e-8a2b-7c3d-9e4f-000000003621"}'
        .',"route":{"candidates":["/national/harbour","/national","/"],"path":"/national/harbour","rest":"harbour","route":"/national"}'
        .',"site":{"handle":"south","host":"south.example","locale":"da","locale_published":true,"site":"01936f5e-8a2b-7c3d-9e4f-000000003602"}'
        .',"visibility":{"at":"2026-03-10T12:00:00.000000Z","decision":"visible","lifecycle":"active","release":"released","rung":11,"stored":"live","valid_until":"2026-03-10T17:00:00.000000Z","window":{"from":"2026-03-10T11:00:00.000000Z","until":"2026-03-10T17:00:00.000000Z"}}}'
        .',"meta":{"canonical_url":"https://north.example/nyheder/harbour","contract":1,"locale":"da","type":"app:article"}'
        .',"problem":null,"status":200}');
});

it('writes the explanation with PathExplanationJson, the one encoding cms:explain --json prints', function (): void {
    $body = new JsonDeliveryDocuments()->body(new DeliveryAnswer(HttpStatus::Ok, DOCUMENT_RECORD, new TypeName('app:article'), new Locale('da'), 'https://north.example/nyheder/harbour', explanation: documentedExplanation()));
    $document = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

    expect(is_array($document) ? $document['explanation'] ?? null : null)->toBe(PathExplanationJson::toArray(documentedExplanation()));
});

it('writes the explanation of a problem with the problem and without a record, and a step it did not reach as null', function (): void {
    $problem = Problem::of(ErrorCode::PathNotFound, 'Nothing is placed at /nyheder/nothing in da at north.example.');
    $explanation = new PathExplanation(ResolveOutcome::LocaleNotPublished, new SiteStep(new Host('north.example'), new Locale('en'), null, null, false));

    expect(new JsonDeliveryDocuments()->body(DeliveryAnswer::problem($problem, $explanation)))->toBe('{"data":null'
        .',"explanation":{"canonical":null,"mount":null,"node":null,"outcome":"locale_not_published","placement":null,"route":null'
        .',"site":{"handle":null,"host":"north.example","locale":"en","locale_published":false,"site":null},"visibility":null}'
        .',"meta":null,"problem":'.new ProblemCodecV1()->encode($problem, ClassificationAccess::Public).',"status":404}');
});

it('writes an open window end and a missing decision input as null', function (): void {
    $explanation = new PathExplanation(
        ResolveOutcome::NotVisible,
        new SiteStep(new Host('north.example'), new Locale('da'), null, null, true),
        visibility: new VisibilityStep(VisibilityDecision::EntryNotActive, new DateTimeImmutable('2026-03-10T12:00:00Z'), null, null, Visibility::Hidden, null, null),
        canonical: new CanonicalStep(null, null, false),
    );
    $body = new JsonDeliveryDocuments()->body(DeliveryAnswer::problem(Problem::of(ErrorCode::PathNotFound, 'Not shown.'), $explanation));

    expect($body)->toContain('"visibility":{"at":"2026-03-10T12:00:00.000000Z","decision":"entry_not_active","lifecycle":null,"release":null,"rung":2,"stored":"hidden","valid_until":null,"window":null}')
        ->and($body)->toContain('"canonical":{"here":false,"placement":null,"url":null}');

    $windowed = new PathExplanation(
        ResolveOutcome::NotVisible,
        new SiteStep(new Host('north.example'), new Locale('da'), null, null, true),
        visibility: new VisibilityStep(VisibilityDecision::AfterWindow, new DateTimeImmutable('2026-03-10T12:00:00Z'), null, null, Visibility::Live, new TimeWindow(null, new DateTimeImmutable('2026-03-10T11:00:00Z')), null),
    );

    expect(new JsonDeliveryDocuments()->body(DeliveryAnswer::problem(Problem::of(ErrorCode::PathNotFound, 'Not shown.'), $windowed)))
        ->toContain('"window":{"from":null,"until":"2026-03-10T11:00:00.000000Z"}');
});

it('stores an answer as its body, format, stale flag and status, and reads back only that form', function (): void {
    $documents = new JsonDeliveryDocuments;
    $stored = new StoredAnswer(HttpStatus::NotFound, AnswerFormat::Problem, '{"code":"path_not_found"}', false);

    expect($documents->fragment($stored))->toBe('{"body":"{\"code\":\"path_not_found\"}","format":"problem","stale":false,"status":404}')
        ->and($documents->stored('{"body":"x","format":"record","stale":true,"status":200}'))->toEqual(new StoredAnswer(HttpStatus::Ok, AnswerFormat::Record, 'x', true))
        ->and($documents->stored('not json'))->toBeNull()
        ->and($documents->stored('{"body":"x","format":"record","stale":true,"status":200,"more":1}'))->toBeNull()
        ->and($documents->stored('{"body":1,"format":"record","stale":true,"status":200}'))->toBeNull()
        ->and($documents->stored('{"body":"x","format":"other","stale":true,"status":200}'))->toBeNull()
        ->and($documents->stored('{"body":"x","format":1,"stale":true,"status":200}'))->toBeNull()
        ->and($documents->stored('{"body":"x","format":"record","stale":"yes","status":200}'))->toBeNull()
        ->and($documents->stored('{"body":"x","format":"record","stale":true,"status":299}'))->toBeNull()
        ->and($documents->stored('{"body":"x","format":"record","stale":true,"status":"200"}'))->toBeNull()
        ->and($documents->stored('{"format":"record","stale":true,"status":200}'))->toBeNull();
});
