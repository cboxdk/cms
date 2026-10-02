<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Actions;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Identity\Login\Domain\Dto\LocalLoginRequest;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginMethod;
use Cbox\Cms\Identity\PasswordReset\Actions\RequestPasswordReset;
use Cbox\Cms\Identity\PasswordReset\Actions\ResetPassword;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\PasswordResetOutcome;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\PasswordResetSubmission;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\ResetRequest;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\ResetRequestOutcome;
use Cbox\Cms\Identity\PasswordReset\Domain\ResetMail;
use Cbox\Cms\Identity\Tests\Login\LocalLoginWorld;
use Cbox\Cms\Identity\Tests\PasswordReset\PasswordResetWorld;
use Cbox\Cms\Identity\Tests\Sessions\SessionWorld;
use DateInterval;
use RuntimeException;

/*
 * The password reset (PRD 5.16) called directly with its DTOs over PasswordResetWorld's fakes
 * (GUARDRAILS 9): a link is mailed only for a known local account whose actor is active, and every
 * request is answered the same; a token sets a password once and not after it expires; the new
 * password is held to the 12-character and breached rules, which leave the token usable; a reset
 * ends every session of the actor and logs in through the login policy with the method
 * password_reset, or, when the policy refuses that, sets the password and logs no one in.
 */

const RESET_EMAIL = 'mette.holm@example.com';

const RESET_IP = '192.0.2.20';

function resetRequest(string $email = RESET_EMAIL): ResetRequest
{
    return new ResetRequest($email, RESET_IP);
}

function newPassword(string $token, string $password = PasswordResetWorld::NEW_PASSWORD, ?TransportCredential $previous = null): PasswordResetSubmission
{
    return new PasswordResetSubmission($token, $password, $previous);
}

it('mails a link only for a known local account whose actor is active, and answers every request the same', function (): void {
    $world = new PasswordResetWorld;
    $world->login->person(RESET_EMAIL);
    $world->login->person('pending@example.com', ActorState::Pending);
    $world->login->person('gone@example.com', ActorState::Deactivated);

    $outcomes = array_map(
        static fn (string $email): ResetRequestOutcome => $world->request()->request(resetRequest($email)),
        [' Mette.Holm@Example.com ', 'nobody@example.com', 'pending@example.com', 'gone@example.com', 'not an email'],
    );
    $sent = $world->mail->sent();

    expect(array_map(static fn (ResetRequestOutcome $outcome): bool => $outcome->taken, $outcomes))->toBe([true, true, true, true, true])
        ->and($sent)->toHaveCount(1)
        ->and($sent[0]->to->value)->toBe(RESET_EMAIL)
        ->and($sent[0]->subject)->toBe(ResetMail::SUBJECT)
        ->and($sent[0]->text)->toContain(PasswordResetWorld::PAGE.'/cms_pr_')
        ->and($sent[0]->text)->toContain('within 60 minutes')
        ->and($world->login->telemetry->counted(RequestPasswordReset::REQUESTS))->toBe(5);
});

it('refuses an email left empty with validation_required and counts no request', function (): void {
    $world = new PasswordResetWorld;

    $outcome = $world->request()->request(resetRequest('   '));

    expect($outcome->taken)->toBeFalse()
        ->and($world->mail->sent())->toBe([])
        ->and($world->login->telemetry->counted(RequestPasswordReset::REQUESTS))->toBe(0);
});

it('sends nothing above the limit of requests for one email, and answers the same', function (): void {
    $world = new PasswordResetWorld;
    $world->login->person(RESET_EMAIL);

    $outcomes = array_map(static fn (int $i): ResetRequestOutcome => $world->request()->request(resetRequest()), range(1, 5));

    expect(array_map(static fn (ResetRequestOutcome $outcome): bool => $outcome->taken, $outcomes))->toBe([true, true, true, true, true])
        ->and($world->mail->sent())->toHaveCount(3);
});

it('answers the same when the mail transport does not take the mail', function (): void {
    $world = new PasswordResetWorld;
    $world->login->person(RESET_EMAIL);
    $world->mail->goDown();

    expect($world->request()->request(resetRequest())->taken)->toBeTrue()
        ->and($world->mail->sent())->toBe([]);
});

it('sets the password with a token once, ends every session of the actor and logs in with a new session', function (): void {
    $world = new PasswordResetWorld;
    $actor = $world->login->person(RESET_EMAIL);
    $first = $world->login->action()->login(new LocalLoginRequest(RESET_EMAIL, LocalLoginWorld::PASSWORD, RESET_IP))->session;
    $second = $world->login->action()->login(new LocalLoginRequest(RESET_EMAIL, LocalLoginWorld::PASSWORD, '198.51.100.7'))->session;
    $world->request()->request(resetRequest());
    $token = $world->mailedToken();
    $world->login->clock->advance(new DateInterval('PT10M'));

    $outcome = $world->reset()->reset(newPassword($token));
    $again = $world->reset()->reset(newPassword($token, 'yet another long passphrase'));

    expect($outcome->session)->not->toBeNull()
        ->and($outcome->session?->session->method)->toBe(LoginMethod::PasswordReset)
        ->and($world->login->sessions->count())->toBe(1)
        ->and($again->refusal)->toBe(ErrorCode::PasswordResetTokenInvalid)
        ->and($again->passwordSet)->toBeFalse()
        ->and($world->login->hasher->verify(new Password(PasswordResetWorld::NEW_PASSWORD), ($world->login->accounts->ofActor($actor->id) ?? throw new RuntimeException('No account.'))->hash))->toBeTrue();

    foreach ([$first, $second] as $ended) {
        expect(fn (): Principal => $world->login->verifier()->verify($ended?->token->credential()))->toThrow(CredentialRejected::class);
    }

    $principal = $world->login->verifier()->verify($outcome->session?->token->credential());

    expect($principal instanceof ActorPrincipal && $principal->actor->equals($actor->id))->toBeTrue()
        ->and($world->login->action()->login(new LocalLoginRequest(RESET_EMAIL, PasswordResetWorld::NEW_PASSWORD, RESET_IP))->session)->not->toBeNull()
        ->and($world->login->action()->login(new LocalLoginRequest(RESET_EMAIL, LocalLoginWorld::PASSWORD, RESET_IP))->refusal)->toBe(ErrorCode::LoginRejected);
});

it('refuses a token once it expires, with the same code as an unknown or malformed one, before it checks or hashes the password', function (): void {
    $world = new PasswordResetWorld;
    $actor = $world->login->person(RESET_EMAIL);
    $world->request()->request(resetRequest());
    $token = $world->mailedToken();
    $world->login->clock->advance(new DateInterval('PT60M'));

    $expired = $world->reset()->reset(newPassword($token));
    $unknown = $world->reset()->reset(newPassword('cms_pr_'.str_repeat('0', 72)));
    $malformed = $world->reset()->reset(newPassword('not-a-token'));

    expect([$expired->refusal, $unknown->refusal, $malformed->refusal])->toBe([ErrorCode::PasswordResetTokenInvalid, ErrorCode::PasswordResetTokenInvalid, ErrorCode::PasswordResetTokenInvalid])
        ->and($expired->aboutPassword())->toBeFalse()
        ->and($world->breached->checks())->toBe(0)
        ->and($world->login->hasher->hashed)->toBe(1)
        ->and($world->login->hasher->verify(new Password(LocalLoginWorld::PASSWORD), ($world->login->accounts->ofActor($actor->id) ?? throw new RuntimeException('No account.'))->hash))->toBeTrue();
});

it('holds the new password to the 12-character and breached rules and keeps the token usable', function (string $password, ErrorCode $code): void {
    $world = new PasswordResetWorld;
    $world->login->person(RESET_EMAIL);
    $world->request()->request(resetRequest());
    $token = $world->mailedToken();

    $refused = $world->reset()->reset(newPassword($token, $password));
    $set = $world->reset()->reset(newPassword($token));

    expect($refused->refusal)->toBe($code)
        ->and($refused->aboutPassword())->toBeTrue()
        ->and($refused->passwordSet)->toBeFalse()
        ->and($set->session)->not->toBeNull();
})->with([
    'eleven characters' => ['elevenchars', ErrorCode::PasswordTooShort],
    'empty' => ['', ErrorCode::ValidationRequired],
    'breached' => [PasswordResetWorld::BREACHED, ErrorCode::PasswordBreached],
    'over 1024 bytes' => [str_repeat('ø', 513), ErrorCode::PasswordTooLong],
]);

it('refuses with breached_passwords_unavailable when the breach check cannot be made, and keeps the token usable', function (): void {
    $world = new PasswordResetWorld;
    $world->login->person(RESET_EMAIL);
    $world->request()->request(resetRequest());
    $token = $world->mailedToken();
    $world->breached->goDown();

    $refused = $world->reset()->reset(newPassword($token));
    $world->breached->comeBack();

    expect($refused->refusal)->toBe(ErrorCode::BreachedPasswordsUnavailable)
        ->and($refused->aboutPassword())->toBeFalse()
        ->and($world->reset()->reset(newPassword($token))->session)->not->toBeNull();
});

it('sets the password but logs no one in when the login policy does not allow a login by reset', function (): void {
    $world = new PasswordResetWorld;
    $world->login->policy = SessionWorld::policy(['staff' => ['methods' => ['password_reset' => false]]]);
    $world->login->person(RESET_EMAIL);
    $first = $world->login->action()->login(new LocalLoginRequest(RESET_EMAIL, LocalLoginWorld::PASSWORD, RESET_IP))->session;
    $world->request()->request(resetRequest());

    $outcome = $world->reset()->reset(newPassword($world->mailedToken(), previous: $first?->token->credential()));

    expect($outcome)->toEqual(PasswordResetOutcome::changed())
        ->and($world->login->sessions->count())->toBe(0)
        ->and($world->login->action()->login(new LocalLoginRequest(RESET_EMAIL, PasswordResetWorld::NEW_PASSWORD, RESET_IP))->session)->not->toBeNull();
});

it('issues no link for an account whose actor is not active or for no account, and says which', function (): void {
    $world = new PasswordResetWorld;
    $world->login->person('robot@example.com', ActorState::Deprovisioned);

    expect($world->links()->issue(new LoginIdentifier('robot@example.com'))->refusal)->toBe(ErrorCode::ActorNotActive)
        ->and($world->links()->issue(new LoginIdentifier('nobody@example.com'))->refusal)->toBe(ErrorCode::LocalAccountMissing);
});

it('prunes the tokens used or expired more than 24 hours ago', function (): void {
    $world = new PasswordResetWorld;
    $world->login->person(RESET_EMAIL);
    $world->request()->request(resetRequest());
    $world->request()->request(resetRequest());
    $world->reset()->reset(newPassword($world->mailedToken()));
    $world->login->clock->advance(new DateInterval('PT24H'));

    expect($world->prune()->prune())->toBe(0);

    $world->login->clock->advance(new DateInterval('PT1H'));

    expect($world->prune()->prune())->toBe(2)
        ->and($world->login->accounts->resetTokens())->toBe(0);
});

it('counts every reset by its outcome and never with the token', function (): void {
    $world = new PasswordResetWorld;
    $world->login->person(RESET_EMAIL);
    $world->request()->request(resetRequest());
    $token = $world->mailedToken();

    $world->reset()->reset(newPassword($token, 'short'));
    $world->reset()->reset(newPassword($token));

    $outcomes = [];

    foreach ($world->login->telemetry->counters() as $counter) {
        if ($counter->name->value === ResetPassword::RESETS) {
            $outcomes[] = [$counter->attributes->get(ResetPassword::OUTCOME), $counter->attributes->get(ResetPassword::ERROR_CODE)];
        }

        expect(json_encode($counter, JSON_THROW_ON_ERROR))->not->toContain($token)
            ->and(json_encode($counter, JSON_THROW_ON_ERROR))->not->toContain(RESET_EMAIL);
    }

    expect($outcomes)->toBe([['refused', 'password_too_short'], ['logged_in', null]]);
});
