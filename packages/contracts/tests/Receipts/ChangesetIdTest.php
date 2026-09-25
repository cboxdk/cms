<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Receipts;

use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Contracts\Ids\Uuid7;

/*
 * The typed id of a changeset, the id a committed receipt carries.
 */

it('wraps a UUIDv7 and gives back its canonical string', function (): void {
    $id = ChangesetId::fromString('01936F5E-8A2B-7C3D-9E4F-5A6B7C8D9E0F');

    expect($id->toString())->toBe('01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0f')
        ->and($id->value)->toEqual(new Uuid7('01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0f'))
        ->and($id->unixMilliseconds())->toBe(0x01936F5E8A2B);
});

it('compares by value', function (): void {
    $id = ChangesetId::fromString('01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0f');

    expect($id->equals(new ChangesetId(new Uuid7('01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0f'))))->toBeTrue()
        ->and($id->equals(ChangesetId::fromString('01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e10')))->toBeFalse();
});

it('rejects a string that is not a UUIDv7 with InvalidUuid7', function (string $value): void {
    expect(static fn (): ChangesetId => ChangesetId::fromString($value))->toThrow(InvalidUuid7::class);
})->with([
    'empty' => '',
    'not hex' => 'not-a-uuid',
    'version 4' => '550e8400-e29b-41d4-a716-446655440000',
    'trailing newline' => "01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0f\n",
]);
