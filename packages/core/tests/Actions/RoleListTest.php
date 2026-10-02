<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Core\Access\Actions\ListRolesAction;
use Cbox\Cms\Core\Access\Domain\Dto\RoleList;
use Cbox\Cms\Core\Access\Domain\Queries\ListRoles;
use Cbox\Cms\Core\Codecs\Boundary\Generated\RoleListCodecV1;
use Cbox\Cms\Core\Tests\Access\ListingActionWorld;
use Cbox\Cms\Core\Tests\Access\ListingWorld;

/*
 * role.list through the query pipeline with fakes (GUARDRAILS 9, PRD 5.10): an actor whose role
 * names role.list reads a page of roles with their ceilings and permissions; an actor without it,
 * and the anonymous principal, are refused as unauthorized; a page holds at most its limit and
 * names the role to read the next page after; and a page costs its limit.
 */

it('lists the roles with their ceilings and permissions for an actor whose role names role.list', function (): void {
    $world = new ListingActionWorld;
    $answer = $world->read(new ListRoles, $world->reader(['role.list'], ClassificationAccess::Internal));
    $result = $answer->result;

    expect($result)->toBeInstanceOf(RoleList::class)
        ->and($result instanceof RoleList ? $result->roles : [])->toEqual(ListingWorld::roles())
        ->and($result instanceof RoleList ? $result->next : 'none')->toBeNull()
        ->and($result instanceof RoleList ? new RoleListCodecV1()->encode($result, $answer->access) : '')
        ->toContain('"handle":"admin"', '"permissions":["actor.list","grant.list","role.list"]', '"ceiling":"personal"');
});

it('pages after a role and names the role to read the next page after', function (): void {
    $world = new ListingActionWorld;
    $credential = $world->reader(['role.list'], ClassificationAccess::Internal);
    $first = $world->read(new ListRoles(limit: 1), $credential)->result;
    $second = $world->read(new ListRoles(RoleId::fromString(ListingWorld::ADMIN_ROLE), 1), $credential)->result;

    expect($first instanceof RoleList ? [count($first->roles), $first->next?->toString()] : [])->toBe([1, ListingWorld::ADMIN_ROLE])
        ->and($second instanceof RoleList ? [$second->roles[0]->handle->value, $second->next] : [])->toBe(['desk', null]);
});

it('refuses an actor whose roles do not name role.list, and the anonymous principal, as unauthorized', function (): void {
    $world = new ListingActionWorld;

    foreach ([$world->reader(['grant.list'], ClassificationAccess::Personal), $world->reader([], ClassificationAccess::Personal), null] as $credential) {
        $answer = $world->read(new ListRoles, $credential);

        expect($answer->result)->toBeNull()
            ->and(array_map(static fn (CatalogError $error): ErrorCode => $error->code, $answer->errors))->toBe([ErrorCode::Unauthorized]);
    }
});

it('costs the rows a page may return', function (): void {
    expect(new ListRolesAction(ListingWorld::accessListings(ListingWorld::ADMIN))->cost(new ListRoles(limit: 7))->units)->toBe(7)
        ->and(new ListRolesAction(ListingWorld::accessListings(ListingWorld::ADMIN))->cost(new ListRoles)->units)->toBe(50);
});
