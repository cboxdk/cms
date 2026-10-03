<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\GrantRevoked;
use Cbox\Cms\Core\Access\Domain\ActorGrantsRef;
use Cbox\Cms\Core\Access\Domain\Commands\RevokeGrant;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeGrantReader;
use Cbox\Cms\Core\Tests\Access\GrantActionWorld;
use Cbox\Cms\Core\Tests\Identity\ActorCommandFakes;
use Cbox\Cms\Core\Tests\Postgres\AccessWorld;

/*
 * grant.revoke (PRD 5.10, 5.16, 6.4, invariant 31) through the command pipeline, called directly
 * with the action and the fakes of the ports and contracts it reads (GUARDRAILS 9). The issuer holds
 * grant.revoke on ROOT unless a test says otherwise. It covers the end of a grant, a grant that has
 * ended, an issuer without grant.revoke on the grant's node, the end of a deny held to the
 * escalation guard, and the version conflicts: a version the caller did not read, a grant that does
 * not exist or lies outside the issuer's regions, and a change after the read.
 */

function grantRevokeWorld(): GrantActionWorld
{
    return new GrantActionWorld()->hold(['grant.revoke'], AccessWorld::ROOT);
}

function revokeCommand(GrantId $grant, int $version = 1): RevokeGrant
{
    return new RevokeGrant($grant, new AggregateVersion($version));
}

it('ends a grant read at the version the caller saw, reading the grant, its role and its actor\'s set of grants', function (): void {
    $world = grantRevokeWorld();
    $world->role(['entry.create', 'grant.assign'], ClassificationAccess::Sensitive);
    $target = $world->target();
    $grant = $world->stored($target, AccessWorld::SPORT, locales: ['da'], version: 3);

    $result = $world->run(revokeCommand($grant, 3));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($world->committer->pending[0]->command->value)->toBe('grant.revoke')
        ->and($world->committer->pending[0]->plan->mutations())->toEqual([new GrantRevoked($grant)])
        ->and($world->reads())->toBe([
            $world->issuer->aggregateKey().' 1',
            new ActorGrantsRef($target)->aggregateKey().' 4',
            'grant:'.GrantActionWorld::GRANT.' 3',
            'role:'.GrantActionWorld::ROLE.' 1',
        ]);
});

it('refuses a grant that has ended with validation_failed at the grant', function (): void {
    $world = grantRevokeWorld();
    $world->role(['entry.create']);
    $grant = $world->stored($world->target(), AccessWorld::NEWS, version: 2, ended: true);

    $result = $world->run(revokeCommand($grant, 2));

    expect(ActorCommandFakes::errors($result))->toBe(['validation_failed grant'])
        ->and($world->committer->pending)->toBe([]);
});

it('rejects an issuer without grant.revoke on the grant\'s node in its locales as unauthorized', function (bool $inEnglish): void {
    $world = new GrantActionWorld()
        ->hold(['grant.revoke'], AccessWorld::CULTURE)
        ->hold(['grant.revoke'], AccessWorld::NEWS, locales: ['da']);
    $world->role(['entry.create']);
    $grant = $world->stored($world->target(), AccessWorld::NEWS, locales: $inEnglish ? ['en'] : null);

    expect(ActorCommandFakes::errors($world->run(revokeCommand($grant))))->toBe(['unauthorized'])
        ->and($world->committer->pending)->toBe([]);
})->with([
    'every locale' => [false],
    'another locale' => [true],
]);

it('holds the end of a deny to the escalation guard, because it gives back what the deny kept out', function (): void {
    $world = grantRevokeWorld()->hold(['entry.create'], AccessWorld::NEWS);
    $world->role(['entry.create', 'entry.publish']);
    $deny = $world->stored($world->target(), AccessWorld::NEWS, GrantEffect::Deny);

    $refused = $world->run(revokeCommand($deny));

    $allowed = grantRevokeWorld()->hold(['entry.create', 'entry.publish'], AccessWorld::NEWS);
    $allowed->role(['entry.create', 'entry.publish']);
    $within = $allowed->run(revokeCommand($allowed->stored($allowed->target(), AccessWorld::NEWS, GrantEffect::Deny)));

    $administrative = grantRevokeWorld()->hold(['entry.create', 'grant.revoke'], AccessWorld::NEWS);
    $administrative->role(['entry.create', 'grant.revoke']);
    $stepUp = $administrative->run(revokeCommand($administrative->stored($administrative->target(), AccessWorld::NEWS, GrantEffect::Deny)));

    expect(ActorCommandFakes::errors($refused))->toBe(['grant_escalation_refused'])
        ->and($within->outcome())->toBe(Outcome::Committed)
        ->and(ActorCommandFakes::errors($stepUp))->toBe(['step_up_required']);
});

it('ends an allow of a role the issuer does not hold, which takes rights away', function (): void {
    $world = grantRevokeWorld();
    $world->role(['entry.publish', 'grant.assign'], ClassificationAccess::Sensitive);

    expect($world->run(revokeCommand($world->stored($world->target(), AccessWorld::CULTURE)))->outcome())->toBe(Outcome::Committed);
});

it('rejects a version the caller did not read, a grant that does not exist and one outside the issuer\'s regions with version_conflict', function (): void {
    $world = grantRevokeWorld();
    $world->role(['entry.create']);
    $grant = $world->stored($world->target(), AccessWorld::SPORT, version: 2);

    $stale = $world->run(revokeCommand($grant, 1));
    $missing = $world->run(revokeCommand(GrantId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000006ff')));

    $outside = grantRevokeWorld();
    $outside->reader = new FakeGrantReader([NodeId::fromString(AccessWorld::CULTURE)]);
    $outside->role(['entry.create']);
    $unreached = $outside->run(revokeCommand($outside->stored($outside->target(), AccessWorld::SPORT)));

    expect(ActorCommandFakes::errors($stale))->toBe(['version_conflict'])
        ->and(ActorCommandFakes::errors($missing))->toBe(['version_conflict'])
        ->and(ActorCommandFakes::errors($unreached))->toBe(['version_conflict'])
        ->and($world->committer->pending)->toBe([])
        ->and($outside->committer->pending)->toBe([]);
});

it('fails with version_conflict when the grant changed after it was read', function (): void {
    $world = grantRevokeWorld();
    $world->role(['entry.create']);
    $grant = $world->stored($world->target(), AccessWorld::NEWS);
    $world->commitWith(new VersionConflict(new StaleRead($grant, AggregateVersion::first(), new AggregateVersion(2))));

    $result = $world->run(revokeCommand($grant));

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(ActorCommandFakes::errors($result))->toBe(['version_conflict'])
        ->and($world->committer->pending)->toHaveCount(1);
});
