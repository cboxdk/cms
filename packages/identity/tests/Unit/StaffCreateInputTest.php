<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Unit;

use Cbox\Cms\Cli\Domain\Dto\CliAnswer;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Identity\Staff\Boundary\StaffCreateInput;
use Cbox\Cms\Identity\Staff\Domain\Dto\StaffRegistration;

// How cms:staff:create reads its input (PRD 5.16): the password from standard input with
// --password-stdin, without the line break at its end, and usage errors that never repeat a value.

/**
 * @return resource
 */
function piped(string $text)
{
    $stream = fopen('php://memory', 'r+');
    assert(is_resource($stream));
    fwrite($stream, $text);
    rewind($stream);

    return $stream;
}

it('reads the password piped to standard input without the line break at its end', function (string $piped, ?string $password): void {
    expect(StaffCreateInput::fromStream(piped($piped)))->toBe($password);
})->with([
    'with a newline' => ["correct horse battery staple\n", 'correct horse battery staple'],
    'with a carriage return and a newline' => ["correct horse battery staple\r\n", 'correct horse battery staple'],
    'without a line break' => ['correct horse battery staple', 'correct horse battery staple'],
    'with spaces, which belong to it' => ["  spaced out password  \n", '  spaced out password  '],
    'nothing' => ['', null],
    'a line break alone' => ["\n", null],
]);

it('takes the typed password only when it was typed the same twice', function (): void {
    $differ = StaffCreateInput::typedTwice('correct horse battery staple', 'correct horse battery stapler');
    $empty = StaffCreateInput::typedTwice('', '');

    expect(StaffCreateInput::typedTwice('correct horse battery staple', 'correct horse battery staple'))->toBe('correct horse battery staple')
        ->and($differ instanceof CliAnswer ? $differ->exit : null)->toBe(ExitCode::Usage)
        ->and($differ instanceof CliAnswer ? implode(' ', $differ->errors) : '')->not->toContain('staple')
        ->and($empty instanceof CliAnswer ? $empty->exit : null)->toBe(ExitCode::Usage);
});

it('refuses invalid options with exit 64 and a message that does not repeat them', function (mixed $email, mixed $name): void {
    $answer = StaffCreateInput::options($email, $name);

    expect($answer?->exit)->toBe(ExitCode::Usage)
        ->and(implode(' ', $answer->errors ?? []))->not->toContain('not-an-address');
})->with([
    'no email' => [null, 'Mette Holm'],
    'no name' => ['mette@example.com', null],
    'an invalid email' => ['not-an-address', 'Mette Holm'],
    'a name with a control character' => ['mette@example.com', "Mette\x07Holm"],
]);

it('builds the registration of valid options and a password, and refuses a missing password with exit 64', function (): void {
    $registration = StaffCreateInput::registration('mette@example.com', 'Mette Holm', 'correct horse battery staple');
    $missing = StaffCreateInput::registration('mette@example.com', 'Mette Holm', null);

    expect(StaffCreateInput::options('mette@example.com', 'Mette Holm'))->toBeNull()
        ->and($registration)->toBeInstanceOf(StaffRegistration::class)
        ->and($registration instanceof StaffRegistration ? $registration->password->reveal() : null)->toBe('correct horse battery staple')
        ->and($missing instanceof CliAnswer ? $missing->exit : null)->toBe(ExitCode::Usage);
});
