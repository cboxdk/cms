<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Domain;

use BackedEnum;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginOutcome;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\PasswordResetOutcome;
use Cbox\Cms\Panel\Domain\ForgotPasswordRefusal;
use Cbox\Cms\Panel\Domain\LoginRefusal;
use Cbox\Cms\Panel\Domain\ResetFormRefusal;
use Cbox\Cms\Panel\Domain\ResetPasswordRefusal;
use LogicException;

/*
 * The refusals the panel's pages show are catalog codes (PRD 6.1): each case of a page's refusal
 * enum is an ErrorCode, and together the cases are every code the identity module's action refuses
 * that form with, so no refusal reaches a page as a code its generated TypeScript type lacks.
 */

/**
 * The codes an outcome's named constructor accepts, of all the catalog's codes.
 *
 * @param  callable(ErrorCode): mixed  $refuse
 * @return list<string>
 */
function refusedWith(callable $refuse): array
{
    $codes = [];

    foreach (ErrorCode::cases() as $code) {
        try {
            $refuse($code);
            $codes[] = $code->value;
        } catch (LogicException) {
        }
    }

    sort($codes);

    return $codes;
}

/**
 * @param  list<BackedEnum>  $cases
 * @return list<string>
 */
function refusalValues(array $cases): array
{
    $values = array_map(static fn (BackedEnum $case): string => (string) $case->value, $cases);
    sort($values);

    return $values;
}

it('gives each refusal the catalog code of its value', function (LoginRefusal|ForgotPasswordRefusal|ResetFormRefusal|ResetPasswordRefusal $refusal): void {
    expect($refusal->code())->toBe(ErrorCode::from($refusal->value));
})->with([...LoginRefusal::cases(), ...ForgotPasswordRefusal::cases(), ...ResetFormRefusal::cases(), ...ResetPasswordRefusal::cases()]);

it('lists every code a login is refused with', function (): void {
    expect(refusalValues(LoginRefusal::cases()))->toBe(refusedWith(LoginOutcome::refused(...)));
});

it('lists every code a password reset is refused with, under the password or the form', function (): void {
    $password = array_values(array_filter(
        refusedWith(PasswordResetOutcome::refused(...)),
        static fn (string $code): bool => PasswordResetOutcome::refused(ErrorCode::from($code))->aboutPassword(),
    ));
    $form = array_values(array_diff(refusedWith(PasswordResetOutcome::refused(...)), $password));

    expect(refusalValues(ResetPasswordRefusal::cases()))->toBe($password)
        ->and(refusalValues(ResetFormRefusal::cases()))->toBe($form)
        ->and(refusalValues(ForgotPasswordRefusal::cases()))->toBe([ErrorCode::ValidationRequired->value]);
});
