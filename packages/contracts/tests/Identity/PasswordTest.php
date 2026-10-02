<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Identity;

use Cbox\Cms\Contracts\Identity\BreachedPasswordsUnavailable;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\Password;
use LogicException;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/*
 * A password is sensitive (GUARDRAILS 6, PRD 5.16): only reveal() gives it, and no string form, dump,
 * export, JSON, serialisation, exception message or stack trace holds it.
 */

const SECRET = 'Tr0ub4dor&3-horse';

/**
 * Everything PHP prints of a value without asking for it.
 */
function printed(mixed $value): string
{
    ob_start();
    var_dump($value);
    $dumped = (string) ob_get_clean();

    return implode("\n", [
        $dumped,
        print_r($value, true),
        var_export($value, true),
        (string) json_encode($value),
        (string) json_encode(['password' => $value]),
        sprintf('%s', $value instanceof Password ? $value : ''),
    ]);
}

it('gives the password only through reveal()', function (): void {
    expect(new Password(SECRET)->reveal())->toBe(SECRET)
        ->and(new Password('rødgrød med fløde')->reveal())->toBe('rødgrød med fløde');
});

it('never prints the password: string form, var_dump, print_r, var_export and json_encode', function (): void {
    $password = new Password(SECRET);

    expect((string) $password)->toBe(Password::REDACTED)
        ->and(json_encode($password))->toBe(json_encode(Password::REDACTED))
        ->and(printed($password))->not->toContain('Tr0ub4')
        ->and(printed([$password, 'nested' => ['deeper' => $password]]))->not->toContain(SECRET);
});

it('refuses to be serialised, in a message without the password', function (): void {
    $password = new Password(SECRET);

    expect(fn (): string => serialize($password))->toThrow(LogicException::class, 'never serialised')
        ->and(fn (): string => serialize(['password' => $password]))->toThrow(LogicException::class)
        ->and(fn (): mixed => unserialize('O:'.strlen(Password::class).':"'.Password::class.'":1:{s:5:"value";s:3:"abc";}'))->toThrow(LogicException::class);

    try {
        serialize($password);
    } catch (LogicException $refused) {
        expect($refused->getMessage())->not->toContain(SECRET);
    }
});

it('never appears in an exception message or a stack trace that passes it', function (): void {
    $throwers = [
        static fn (Password $password): never => throw new RuntimeException(sprintf('Refused %s.', $password)),
        static fn (Password $password): never => throw BreachedPasswordsUnavailable::because('the service did not answer.'),
        static fn (Password $password): never => throw new RuntimeException('Refused '.json_encode($password).'.'),
    ];

    foreach ($throwers as $thrower) {
        try {
            $thrower(new Password(SECRET));
        } catch (Throwable $thrown) {
            expect($thrown->getMessage())->not->toContain(SECRET)
                ->and($thrown->getTraceAsString())->not->toContain(SECRET)
                ->and((string) $thrown)->not->toContain(SECRET)
                ->and(print_r($thrown->getTrace(), true))->not->toContain(SECRET);
        }
    }
});

it('is not empty, and keeps the raw string out of a stack trace of its constructor', function (): void {
    $parameter = new ReflectionParameter([Password::class, '__construct'], 'value');

    expect(fn (): Password => new Password(''))->toThrow(InvalidIdentity::class, 'A password is not empty.')
        ->and($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
});
