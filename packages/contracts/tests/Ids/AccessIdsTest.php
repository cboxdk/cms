<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Ids;

use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Ids\Uuid7;

/*
 * The ids and the effect of access (PRD 5.10): a role and a grant are UUIDv7 ids with an aggregate
 * key of their own kind, and a grant allows or denies.
 */

it('parses a role id and a grant id, compares them by value and keys them by kind', function (): void {
    $value = '0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
    $role = RoleId::fromString($value);
    $grant = GrantId::fromString($value);

    expect($role->toString())->toBe($value)
        ->and($role->aggregateKey())->toBe('role:'.$value)
        ->and($role->equals(new RoleId(new Uuid7($value))))->toBeTrue()
        ->and($role->equals(RoleId::fromString('0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c')))->toBeFalse()
        ->and($grant->toString())->toBe($value)
        ->and($grant->aggregateKey())->toBe('grant:'.$value)
        ->and($grant->equals(new GrantId(new Uuid7($value))))->toBeTrue()
        ->and($grant->equals(GrantId::fromString('0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c')))->toBeFalse()
        ->and(fn (): RoleId => RoleId::fromString('desk'))->toThrow(InvalidUuid7::class)
        ->and(fn (): GrantId => GrantId::fromString('grant'))->toThrow(InvalidUuid7::class);
});

it('stores a grant\'s effect as allow or deny', function (): void {
    expect(array_map(static fn (GrantEffect $effect): string => $effect->value, GrantEffect::cases()))->toBe(['allow', 'deny']);
});
