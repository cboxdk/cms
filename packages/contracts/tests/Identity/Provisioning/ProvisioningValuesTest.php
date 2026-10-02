<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Identity\Provisioning;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\Provisioning\MembershipChange;
use Cbox\Cms\Contracts\Identity\Provisioning\ResourceVersion;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimChange;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimErrorCode;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimGroup;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimIdempotencyKey;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimRefused;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimResourceId;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimResourceType;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimUser;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimUserName;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimUserOutcome;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimUserResource;

/*
 * The values of SCIM provisioning (PRD 5.16): each in its form, the idempotency key that tells a
 * repeated request from a new one, and refusal codes that are codes of the error catalog.
 */

function provisionedUser(bool $active = true): ScimUser
{
    return new ScimUser(new Subject('00u-ada'), new ScimUserName('ada@example.org'), new DisplayName('Ada'), new EmailAddress('ada@example.org'), $active);
}

it('takes resource ids, versions and userNames in their form', function (): void {
    expect(new ScimResourceId('user-1')->value)->toBe('user-1')
        ->and(new ResourceVersion(3)->next()->value)->toBe(4)
        ->and(ResourceVersion::first()->etag())->toBe('W/"1"')
        ->and(new ScimUserName('Åse Ødegård')->sameAs(new ScimUserName('åse ødegård')))->toBeTrue()
        ->and(new ScimUserName('ada')->equals(new ScimUserName('Ada')))->toBeFalse();

    foreach ([static fn (): ScimResourceId => new ScimResourceId('bulkId'), static fn (): ScimResourceId => new ScimResourceId(''), static fn (): ResourceVersion => new ResourceVersion(0), static fn (): ScimUserName => new ScimUserName(' padded'), static fn (): ScimUserName => new ScimUserName(str_repeat('a', 256))] as $refused) {
        expect($refused)->toThrow(InvalidIdentity::class);
    }
});

it('keeps a group\'s members sorted and each once', function (): void {
    $group = new ScimGroup(new DisplayName('Editors'), null, [new ScimResourceId('user-2'), new ScimResourceId('user-1')]);

    expect(array_map(static fn (ScimResourceId $id): string => $id->value, $group->members))->toBe(['user-1', 'user-2'])
        ->and($group->equals(new ScimGroup(new DisplayName('Editors'), null, [new ScimResourceId('user-1'), new ScimResourceId('user-2')])))->toBeTrue()
        ->and($group->sameNameAs(new ScimGroup(new DisplayName('EDITORS'))))->toBeTrue()
        ->and(static fn (): ScimGroup => new ScimGroup(new DisplayName('Editors'), null, [new ScimResourceId('user-1'), new ScimResourceId('user-1')]))->toThrow(InvalidIdentity::class);
});

it('applies a membership change and refuses an empty or contradictory one', function (): void {
    $change = new MembershipChange(add: [new ScimResourceId('user-3')], remove: [new ScimResourceId('user-1')]);

    expect(array_map(static fn (ScimResourceId $id): string => $id->value, $change->applyTo([new ScimResourceId('user-1'), new ScimResourceId('user-2')])))->toBe(['user-2', 'user-3'])
        ->and(static fn (): MembershipChange => new MembershipChange)->toThrow(InvalidIdentity::class)
        ->and(static fn (): MembershipChange => new MembershipChange(add: [new ScimResourceId('user-1')], remove: [new ScimResourceId('user-1')]))->toThrow(InvalidIdentity::class);
});

it('keys a change by connection, resource, desired state and version', function (): void {
    $connection = new ConnectionId('entra-acme');
    $id = new ScimResourceId('user-1');
    $key = static fn (ScimUser $user, ?ResourceVersion $version, string $connection = 'entra-acme'): ScimIdempotencyKey => new ScimIdempotencyKey(new ConnectionId($connection), ScimResourceType::User, $id, $user->canonical(), $version);
    $first = $key(provisionedUser(false), ResourceVersion::first());

    expect($first->equals($key(provisionedUser(false), ResourceVersion::first())))->toBeTrue()
        ->and($first->equals($key(provisionedUser(false), new ResourceVersion(3))))->toBeFalse()
        ->and($first->equals($key(provisionedUser(), ResourceVersion::first())))->toBeFalse()
        ->and($first->equals($key(provisionedUser(false), ResourceVersion::first(), 'okta-globex')))->toBeFalse()
        ->and($first->equals($key(provisionedUser(false), null)))->toBeFalse()
        ->and($first->unitOfWork()->value)->toMatch('/\Ascim:[0-9a-f]{64}\z/')
        ->and($first->connection->equals($connection))->toBeTrue()
        ->and(ScimIdempotencyKey::canonical('ab', 'c'))->not->toBe(ScimIdempotencyKey::canonical('a', 'bc'));
});

it('pairs changes with a key in an outcome', function (): void {
    $resource = new ScimUserResource(new ScimResourceId('user-1'), new ConnectionId('entra-acme'), provisionedUser(), ResourceVersion::first());
    $key = new ScimIdempotencyKey(new ConnectionId('entra-acme'), ScimResourceType::User, new ScimResourceId('user-1'), provisionedUser()->canonical(), null);

    expect(new ScimUserOutcome($resource, [ScimChange::Created], $key)->changed())->toBeTrue()
        ->and(new ScimUserOutcome($resource, [], null)->changed())->toBeFalse()
        ->and(static fn (): ScimUserOutcome => new ScimUserOutcome($resource, [ScimChange::Created], null))->toThrow(InvalidIdentity::class)
        ->and(static fn (): ScimUserOutcome => new ScimUserOutcome($resource, [], $key))->toThrow(InvalidIdentity::class)
        ->and(static fn (): ScimUserOutcome => new ScimUserOutcome($resource, [ScimChange::Replaced, ScimChange::Replaced], $key))->toThrow(InvalidIdentity::class);
});

it('gives every refusal a code of the error catalog with the status of RFC 7644', function (ScimErrorCode $reason, HttpStatus $status, ?string $scimType): void {
    expect(ErrorCode::from($reason->value)->entry()->http)->toBe($status)
        ->and($reason->scimType())->toBe($scimType)
        ->and(ScimRefused::because($reason)->reason)->toBe($reason);
})->with([
    [ScimErrorCode::ResourceNotFound, HttpStatus::NotFound, null],
    [ScimErrorCode::Uniqueness, HttpStatus::Conflict, 'uniqueness'],
    [ScimErrorCode::VersionMismatch, HttpStatus::PreconditionFailed, null],
    [ScimErrorCode::Mutability, HttpStatus::BadRequest, 'mutability'],
    [ScimErrorCode::InvalidValue, HttpStatus::BadRequest, 'invalidValue'],
    [ScimErrorCode::ReactivationRefused, HttpStatus::Conflict, null],
]);
