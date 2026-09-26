<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\InvalidIdempotencyValue;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\InvalidCommandName;
use Cbox\Cms\Contracts\Ids\InvalidPrincipalId;
use Cbox\Cms\Contracts\Ids\PrincipalId;

/*
 * How the value objects of the idempotency scope show rejected input in their messages: at most
 * 64 bytes, then "...", with control characters, bytes above ASCII, the quote and the backslash
 * escaped, so a message is one readable line whatever the input was.
 */

/**
 * The message a constructor throws for the value.
 *
 * @param  Closure(string): mixed  $make
 */
function rejectionOf(Closure $make, string $value): string
{
    try {
        $make($value);
    } catch (InvalidArgumentException $rejected) {
        return $rejected->getMessage();
    }

    throw new UnexpectedValueException("The value was accepted: {$value}");
}

dataset('rejecting constructors', [
    'a principal id' => [
        static fn (string $value): PrincipalId => new PrincipalId($value),
        InvalidPrincipalId::class,
        'A principal id is 1 to 255 visible ASCII characters, without spaces, got "%s".',
    ],
    'a command name' => [
        static fn (string $value): CommandName => new CommandName($value),
        InvalidCommandName::class,
        'A command name is dot-separated snake_case segments, for example "entry.release", got "%s".',
    ],
    'an idempotency key' => [
        static fn (string $value): IdempotencyKey => new IdempotencyKey($value),
        InvalidIdempotencyValue::class,
        'An idempotency key is 1 to 255 visible ASCII characters, without spaces, got "%s".',
    ],
    'a content hash' => [
        static fn (string $value): ContentHash => new ContentHash($value),
        InvalidIdempotencyValue::class,
        'A content hash is a SHA-256 digest as 64 hex digits, got "%s".',
    ],
]);

it('shows rejected input of up to 64 bytes whole, escaped', function (Closure $make, string $class, string $message): void {
    // 61 characters, a backslash, a quote and a line break: 64 bytes, none cut.
    $value = str_repeat('a b', 20).'x\\"'."\n";

    expect(strlen($value))->toBe(64)
        ->and(static fn (): mixed => $make($value))->toThrow($class)
        ->and(rejectionOf($make, $value))->toBe(sprintf($message, str_repeat('a b', 20).'x\\\\\"\n'));
})->with('rejecting constructors');

it('cuts rejected input after 64 bytes and marks the cut', function (Closure $make, string $class, string $message): void {
    $value = str_repeat('"', 64).'tail that is not shown';

    expect(rejectionOf($make, $value))->toBe(sprintf($message, str_repeat('\"', 64).'...'))
        ->and(rejectionOf($make, str_repeat(' ', 65)))->toBe(sprintf($message, str_repeat(' ', 64).'...'));
})->with('rejecting constructors');

it('escapes bytes that are not visible ASCII as octal', function (Closure $make, string $class, string $message): void {
    expect(rejectionOf($make, "\0é\x7F"))->toBe(sprintf($message, '\000\303\251\177'));
})->with('rejecting constructors');

it('names the empty input as empty quotes', function (Closure $make, string $class, string $message): void {
    expect(rejectionOf($make, ''))->toBe(sprintf($message, ''));
})->with('rejecting constructors');
