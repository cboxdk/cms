<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Fields\Omitted;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Core\Access\Domain\Dto\GrantList;
use Cbox\Cms\Core\Access\Domain\Dto\ListedGrant;
use Cbox\Cms\Core\Access\Domain\Queries\ListGrants;
use Cbox\Cms\Core\Codecs\Boundary\Generated\GrantListCodecV1;
use Cbox\Cms\Core\Tests\Access\ListingActionWorld;
use Cbox\Cms\Core\Tests\Access\ListingWorld;

/*
 * grant.list through the query pipeline with fakes (GUARDRAILS 9, PRD 5.10, 12.2): an actor whose
 * role names grant.list reads a page of grants with their actors' profiles, roles, nodes and
 * locales; an actor without it, and the anonymous principal, are refused as unauthorized. A
 * profile is personal data: the result's codec writes it at the classification access the
 * pipeline answered with, so a reader below personal never sees another actor's email, even when
 * the port gave the profile.
 */

it('lists the grants with their actors\' profiles for an actor whose role names grant.list, at personal access', function (): void {
    $world = new ListingActionWorld;
    $answer = $world->read(new ListGrants, $world->reader(['grant.list'], ClassificationAccess::Personal));
    $result = $answer->result;
    $document = $result instanceof GrantList ? new GrantListCodecV1()->encode($result, $answer->access) : '';

    expect($result)->toBeInstanceOf(GrantList::class)
        ->and($answer->access)->toBe(ClassificationAccess::Personal)
        ->and($result instanceof GrantList ? array_map(static fn (ListedGrant $grant): string => $grant->id->toString(), $result->grants) : [])
        ->toBe([ListingWorld::GRANT_ADMIN, ListingWorld::GRANT_EDITOR, ListingWorld::GRANT_BOB, ListingWorld::GRANT_DENIED])
        ->and($document)->toContain('"email":"eve@example.com"', '"display_name":"Ada Admin"', '"node_label":"north/nyheder"', '"locales":["da","en"]', '"role_handle":"desk"')
        ->and(new GrantListCodecV1()->decode($document, ClassificationAccess::Personal))->toEqual($result);
});

it('leaves every profile value out for a reader whose classification access is below personal', function (): void {
    $world = new ListingActionWorld;
    $answer = $world->read(new ListGrants, $world->reader(['grant.list'], ClassificationAccess::Confidential));
    $result = $answer->result;
    $document = $result instanceof GrantList ? new GrantListCodecV1()->encode($result, $answer->access) : '';
    $decoded = new GrantListCodecV1()->decode($document, ClassificationAccess::Confidential);

    expect($answer->access)->toBe(ClassificationAccess::Confidential)
        ->and($document)->not->toContain('@example.com')
        ->and($document)->not->toContain('Eve Editor')
        ->and($document)->toContain('"profile":{}', '"profile":null')
        ->and($decoded->grants[1]->profile?->email)->toBe(Omitted::Field)
        ->and($decoded->grants[1]->profile?->displayName)->toBe(Omitted::Field)
        ->and($decoded->grants[2]->profile)->toBeNull();
});

it('pages after a grant', function (): void {
    $world = new ListingActionWorld;
    $result = $world->read(new ListGrants(GrantId::fromString(ListingWorld::GRANT_EDITOR), 1), $world->reader(['grant.list'], ClassificationAccess::Personal))->result;

    expect($result instanceof GrantList ? [$result->grants[0]->id->toString(), $result->next?->toString()] : [])->toBe([ListingWorld::GRANT_BOB, ListingWorld::GRANT_BOB]);
});

it('refuses an actor whose roles do not name grant.list, and the anonymous principal, as unauthorized', function (): void {
    $world = new ListingActionWorld;

    foreach ([$world->reader(['role.list', 'actor.list'], ClassificationAccess::Personal), null] as $credential) {
        $answer = $world->read(new ListGrants, $credential);

        expect($answer->result)->toBeNull()
            ->and(array_map(static fn (CatalogError $error): ErrorCode => $error->code, $answer->errors))->toBe([ErrorCode::Unauthorized]);
    }
});
