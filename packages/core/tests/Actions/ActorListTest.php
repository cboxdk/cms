<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Fields\Omitted;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ActorListCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Identity\Domain\Dto\ActorList;
use Cbox\Cms\Core\Identity\Domain\Dto\ListedActor;
use Cbox\Cms\Core\Identity\Domain\Queries\ListActors;
use Cbox\Cms\Core\Tests\Access\ListingActionWorld;
use Cbox\Cms\Core\Tests\Access\ListingWorld;

/*
 * actor.list through the query pipeline with fakes (GUARDRAILS 9, PRD 5.16, 12.2): an actor whose
 * role names actor.list reads a page of the staff actors with their profiles; an actor without it,
 * and the anonymous principal, are refused as unauthorized. A reader below personal gets every
 * profile value as Omitted through the result's codec, so it never sees another actor's email.
 */

it('lists the staff actors with their profiles for an actor whose role names actor.list, at personal access', function (): void {
    $world = new ListingActionWorld;
    $answer = $world->read(new ListActors, $world->reader(['actor.list'], ClassificationAccess::Personal));
    $result = $answer->result;
    $document = $result instanceof ActorList ? new ActorListCodecV1()->encode($result, $answer->access) : '';

    expect($result instanceof ActorList ? array_map(static fn (ListedActor $actor): string => $actor->id->toString(), $result->actors) : [])
        ->toBe([ListingWorld::ADMIN, ListingWorld::EDITOR, ListingWorld::BOB])
        ->and($document)->toContain('"display_name":"Ada Admin","email":"ada@example.com"', '"state":"active"')
        ->and(new ActorListCodecV1()->decode($document, ClassificationAccess::Personal))->toEqual($result);
});

it('leaves every profile value out for a reader whose classification access is below personal', function (): void {
    $world = new ListingActionWorld;
    $answer = $world->read(new ListActors, $world->reader(['actor.list'], ClassificationAccess::Internal));
    $result = $answer->result;
    $document = $result instanceof ActorList ? new ActorListCodecV1()->encode($result, $answer->access) : '';
    $decoded = new ActorListCodecV1()->decode($document, ClassificationAccess::Internal);

    expect($answer->access)->toBe(ClassificationAccess::Internal)
        ->and($document)->not->toContain('@example.com')
        ->and($document)->toContain('"profile":{}')
        ->and($decoded->actors[0]->profile?->email)->toBe(Omitted::Field)
        ->and(static fn (): ActorList => new ActorListCodecV1()->decode(str_replace('"profile":{}', '"profile":{"display_name":"Ada Admin","email":"ada@example.com"}', $document), ClassificationAccess::Internal))
        ->toThrow(DecodingFailed::class);
});

it('pages after an actor', function (): void {
    $world = new ListingActionWorld;
    $result = $world->read(new ListActors(ActorId::fromString(ListingWorld::ADMIN), 2), $world->reader(['actor.list'], ClassificationAccess::Personal))->result;

    expect($result instanceof ActorList ? [count($result->actors), $result->next] : [])->toBe([2, null]);
});

it('refuses an actor whose roles do not name actor.list, and the anonymous principal, as unauthorized', function (): void {
    $world = new ListingActionWorld;

    foreach ([$world->reader(['grant.list'], ClassificationAccess::Personal), null] as $credential) {
        $answer = $world->read(new ListActors, $credential);

        expect($answer->result)->toBeNull()
            ->and(array_map(static fn (CatalogError $error): ErrorCode => $error->code, $answer->errors))->toBe([ErrorCode::Unauthorized]);
    }
});
