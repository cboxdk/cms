<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Ids;

use Cbox\Cms\Contracts\Ids\InvalidPrincipalId;
use Cbox\Cms\Contracts\Ids\PrincipalId;

/*
 * The id of the actor or source a command runs for: 1 to 255 visible ASCII characters, the form the
 * principal string of IdempotencyScope had before it was typed.
 */

it('keeps a principal id exactly as given', function (string $value): void {
    expect((new PrincipalId($value))->value)->toBe($value);
})->with([
    'actor' => 'user:42',
    'source' => 'feed_reuters',
    'uuid' => '01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0f',
    'one character' => 'x',
    'every visible character' => implode('', array_map(chr(...), range(0x21, 0x7E))),
    'longest' => str_repeat('p', PrincipalId::MAX_LENGTH),
]);

it('rejects a principal id that is empty, too long or not visible ASCII', function (string $value): void {
    expect(static fn (): PrincipalId => new PrincipalId($value))
        ->toThrow(InvalidPrincipalId::class, 'A principal id is 1 to 255 visible ASCII characters');
})->with([
    'empty' => '',
    'too long' => str_repeat('p', PrincipalId::MAX_LENGTH + 1),
    'space' => 'user 42',
    'trailing newline' => "user:42\n",
    'tab' => "user\t42",
    'DEL' => "user\x7F42",
    'NUL' => "user\x0042",
    'non-ASCII' => 'brugér:42',
]);

it('shows rejected input cut and escaped in the message', function (): void {
    expect(static fn (): PrincipalId => new PrincipalId(str_repeat('p', 300)."\n"))
        ->toThrow(InvalidPrincipalId::class, '"'.str_repeat('p', 64).'...".');

    expect(static fn (): PrincipalId => new PrincipalId("a\nb"))
        ->toThrow(InvalidPrincipalId::class, 'got "a\\nb".');
});

it('compares principal ids exactly, case included', function (): void {
    expect(new PrincipalId('User:42')->equals(new PrincipalId('User:42')))->toBeTrue()
        ->and(new PrincipalId('User:42')->equals(new PrincipalId('user:42')))->toBeFalse();
});
