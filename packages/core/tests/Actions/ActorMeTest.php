<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ActorMeCodecV1;
use Cbox\Cms\Core\Identity\Actions\WhoAmIAction;
use Cbox\Cms\Core\Identity\Domain\Dto\ActorMe;
use Cbox\Cms\Core\Identity\Domain\OwnActorMissing;
use Cbox\Cms\Core\Identity\Domain\Queries\WhoAmI;
use Cbox\Cms\Core\Tests\Access\ListingActionWorld;
use Cbox\Cms\Core\Tests\Access\ListingWorld;
use Cbox\Cms\Core\Tests\Identity\Fakes\FakeOwnActorReader;

/*
 * actor.me through the query pipeline with fakes (GUARDRAILS 9, PRD 5.16, 13.4): an actor reads its
 * own actor with its profile and grants, with no role naming actor.me, because the query is an
 * ActorQuery; the anonymous principal is refused as unauthorized; and the result's codec writes
 * the subject's own profile whatever the reader's classification access, so a person at public
 * access reads their own name and email.
 */

it('gives an actor its own profile and grants without a permission', function (): void {
    $world = new ListingActionWorld;
    $answer = $world->read(new WhoAmI, $world->reader([], ClassificationAccess::Public));
    $result = $answer->result;

    expect($result)->toEqual(ListingWorld::own(ListingWorld::ADMIN))
        ->and($world->authorizer->asked)->toHaveCount(1)
        ->and($answer->contentKeys)->toBe([])
        ->and($result instanceof ActorMe ? new ActorMeCodecV1()->encode($result, $answer->access) : '')
        ->toContain('"display_name":"Ada Admin"', '"email":"ada@example.com"', '"class":"staff"', '"role_handle":"admin"', '"effect":"allow"', '"locales":null');
});

it('writes the same document for a reader at public access as at personal access', function (): void {
    $world = new ListingActionWorld;
    $public = $world->read(new WhoAmI, $world->reader([], ClassificationAccess::Public));
    $personal = $world->read(new WhoAmI, $world->reader([], ClassificationAccess::Personal));
    $codec = new ActorMeCodecV1;

    expect($public->access)->toBe(ClassificationAccess::Public)
        ->and($public->result instanceof ActorMe ? $codec->encode($public->result, $public->access) : 'public')
        ->toBe($personal->result instanceof ActorMe ? $codec->encode($personal->result, $personal->access) : 'personal');
});

it('refuses the anonymous principal as unauthorized', function (): void {
    $answer = new ListingActionWorld()->read(new WhoAmI, null);

    expect($answer->result)->toBeNull()
        ->and(array_map(static fn (CatalogError $error): ErrorCode => $error->code, $answer->errors))->toBe([ErrorCode::Unauthorized]);
});

it('costs one unit, and treats a context the reader cannot read as a bug', function (): void {
    $action = new WhoAmIAction(new FakeOwnActorReader(null));

    expect($action->cost(new WhoAmI)->units)->toBe(WhoAmIAction::COST)
        ->and(static fn (): ActorMe => $action->handle(new WhoAmI))->toThrow(OwnActorMissing::class);
});
