<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Idempotency;

use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Contracts\Idempotency\InvalidIdempotencyValue;
use Cbox\Cms\Contracts\Idempotency\PrincipalKind;

/*
 * The idempotency value objects of PRD 6.1: the key, the scope it is unique in (actor or source,
 * plus command type) and the hash of the command's content.
 */

const SHA256_OF_ABC = 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad';

it('keeps an idempotency key exactly as given', function (string $key): void {
    expect((new IdempotencyKey($key))->value)->toBe($key);
})->with([
    'uuid' => '01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0f',
    'ingestion key' => 'feed:article-42:v7',
    'one character' => 'x',
    'every visible character' => implode('', array_map(chr(...), range(0x21, 0x7E))),
    'longest' => str_repeat('k', IdempotencyKey::MAX_LENGTH),
]);

it('rejects an idempotency key that is empty, too long or not visible ASCII', function (string $key): void {
    expect(static fn (): IdempotencyKey => new IdempotencyKey($key))
        ->toThrow(InvalidIdempotencyValue::class, 'An idempotency key is 1 to 255 visible ASCII characters');
})->with([
    'empty' => '',
    'too long' => str_repeat('k', IdempotencyKey::MAX_LENGTH + 1),
    'space' => 'two words',
    'trailing newline' => "key\n",
    'tab' => "a\tb",
    'non-ASCII' => 'nøgle',
    'NUL' => "a\0b",
]);

it('compares idempotency keys exactly, case included', function (): void {
    expect(new IdempotencyKey('Key-1')->equals(new IdempotencyKey('Key-1')))->toBeTrue()
        ->and(new IdempotencyKey('Key-1')->equals(new IdempotencyKey('key-1')))->toBeFalse();
});

it('scopes a key to an actor or a source and a command type', function (): void {
    $actor = IdempotencyScope::forActor('user:42', 'entry.release');
    $source = IdempotencyScope::forSource('feed_reuters', 'entry.create');

    expect($actor->kind)->toBe(PrincipalKind::Actor)
        ->and($actor->principal)->toBe('user:42')
        ->and($actor->commandType)->toBe('entry.release')
        ->and($actor)->toEqual(new IdempotencyScope(PrincipalKind::Actor, 'user:42', 'entry.release'))
        ->and($source->kind)->toBe(PrincipalKind::Source)
        ->and($source)->toEqual(new IdempotencyScope(PrincipalKind::Source, 'feed_reuters', 'entry.create'));
});

it('treats another kind, principal or command type as another scope', function (): void {
    $scope = IdempotencyScope::forActor('user:42', 'entry.release');

    expect($scope->equals(IdempotencyScope::forActor('user:42', 'entry.release')))->toBeTrue()
        ->and($scope->equals(IdempotencyScope::forSource('user:42', 'entry.release')))->toBeFalse()
        ->and($scope->equals(IdempotencyScope::forActor('user:43', 'entry.release')))->toBeFalse()
        ->and($scope->equals(IdempotencyScope::forActor('user:42', 'entry.publish')))->toBeFalse();
});

it('rejects a principal that is empty, too long or not visible ASCII', function (PrincipalKind $kind, string $principal): void {
    expect(static fn (): IdempotencyScope => new IdempotencyScope($kind, $principal, 'entry.release'))
        ->toThrow(InvalidIdempotencyValue::class, "An idempotency scope names its {$kind->value} with 1 to 255 visible ASCII characters");
})->with([PrincipalKind::Actor, PrincipalKind::Source])->with([
    'empty' => '',
    'too long' => str_repeat('p', IdempotencyScope::MAX_PRINCIPAL_LENGTH + 1),
    'space' => 'user 42',
    'trailing newline' => "user:42\n",
]);

it('rejects a command type that is not a command name', function (string $type): void {
    expect(static fn (): IdempotencyScope => IdempotencyScope::forActor('user:42', $type))
        ->toThrow(InvalidIdempotencyValue::class, 'names a command type as dot-separated snake_case segments');
})->with(['', 'entry', 'Entry.release', 'entry.release@1', "entry.release\n"]);

it('hashes content with SHA-256', function (): void {
    expect(ContentHash::of('abc')->value)->toBe(SHA256_OF_ABC)
        ->and(ContentHash::of('abc')->equals(new ContentHash(SHA256_OF_ABC)))->toBeTrue()
        ->and(ContentHash::of('abd')->equals(ContentHash::of('abc')))->toBeFalse();
});

it('stores an upper case hex digest in lower case', function (): void {
    expect((new ContentHash(strtoupper(SHA256_OF_ABC)))->value)->toBe(SHA256_OF_ABC);
});

it('rejects a content hash that is not 64 hex digits', function (string $hash): void {
    expect(static fn (): ContentHash => new ContentHash($hash))
        ->toThrow(InvalidIdempotencyValue::class, 'A content hash is a SHA-256 digest as 64 hex digits');
})->with([
    'empty' => '',
    'too short' => substr(SHA256_OF_ABC, 1),
    'too long' => SHA256_OF_ABC.'0',
    'not hex' => str_repeat('g', 64),
    'prefixed' => 'sha256:'.SHA256_OF_ABC,
    'trailing newline' => SHA256_OF_ABC."\n",
]);
