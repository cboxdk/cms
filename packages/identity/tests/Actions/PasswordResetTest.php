<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Actions;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\IdpIdentity;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Identity\PasswordResetToken;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Identity\Login\Boundary\LoginInput;
use Cbox\Cms\Identity\Login\Domain\ClientAddress;
use Cbox\Cms\Identity\Login\Domain\Dto\LocalLoginRequest;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginThrottleKeys;
use Cbox\Cms\Identity\Login\Domain\ThrottleScope;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginMethod;
use Cbox\Cms\Identity\PasswordReset\Actions\RequestPasswordReset;
use Cbox\Cms\Identity\PasswordReset\Actions\ResetPassword;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\PasswordResetOutcome;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\PasswordResetSubmission;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\ResetRequest;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\ResetRequestOutcome;
use Cbox\Cms\Identity\PasswordReset\Domain\ResetMail;
use Cbox\Cms\Identity\Tests\Login\LocalLoginWorld;
use Cbox\Cms\Identity\Tests\Login\ThrottleSecrets;
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
 * password_reset, or, when the policy refuses the factors, sets the password and logs no one in;
 * an actor the policy keeps from a local login by reset (linked to an authoritative connection,
 * password_reset or local login off, not active since the link was issued) gets no link and no
 * password, and its token is refused as invalid.
 */

const RESET_EMAIL = 'mette.holm@example.com';

const RESET_IP = '192.0.2.20';

function resetRequest(string $email = RESET_EMAIL): ResetRequest
{
    return new ResetRequest(LoginInput::login($email), new ClientAddress(RESET_IP));
}

/**
 * Whether the actor's local password is still $password.
 */
function passwordIs(PasswordResetWorld $world, ActorId $actor, string $password): bool
{
    return $world->login->hasher->verify(new Password($password), ($world->login->accounts->ofActor($actor) ?? throw new RuntimeException('No account.'))->hash);
}

function newPassword(string $token, string $password = PasswordResetWorld::NEW_PASSWORD, ?TransportCredential $previous = null): PasswordResetSubmission
{
    return new PasswordResetSubmission(PasswordResetToken::parse($token), LoginInput::password($password), $previous);
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

it('sends nothing for a request without a client address, counts no throttle attempt and answers the same', function (): void {
    $world = new PasswordResetWorld;
    $world->login->person(RESET_EMAIL);

    $outcome = $world->request()->request(new ResetRequest(LoginInput::login(RESET_EMAIL), null));

    expect($outcome->taken)->toBeTrue()
        ->and($world->mail->sent())->toBe([])
        ->and($world->throttle->count(ThrottleScope::Identifier, LoginThrottleKeys::of(ThrottleSecrets::fixed(), new LoginIdentifier(RESET_EMAIL), new ClientAddress(RESET_IP))))->toBe(0)
        ->and($world->login->telemetry->counted(RequestPasswordReset::REQUESTS))->toBe(1);
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
    $first = $world->login->action()->login(new LocalLoginRequest(LoginInput::login(RESET_EMAIL), LoginInput::password(LocalLoginWorld::PASSWORD), new ClientAddress(RESET_IP)))->session;
    $second = $world->login->action()->login(new LocalLoginRequest(LoginInput::login(RESET_EMAIL), LoginInput::password(LocalLoginWorld::PASSWORD), new ClientAddress('198.51.100.7')))->session;
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
        ->and($world->login->action()->login(new LocalLoginRequest(LoginInput::login(RESET_EMAIL), LoginInput::password(PasswordResetWorld::NEW_PASSWORD), new ClientAddress(RESET_IP)))->session)->not->toBeNull()
        ->and($world->login->action()->login(new LocalLoginRequest(LoginInput::login(RESET_EMAIL), LoginInput::password(LocalLoginWorld::PASSWORD), new ClientAddress(RESET_IP)))->refusal)->toBe(ErrorCode::LoginRejected);
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

it('sets the password but logs no one in when the login policy refuses the factors a reset gives', function (): void {
    $world = new PasswordResetWorld;
    $world->login->person(RESET_EMAIL);
    $first = $world->login->action()->login(new LocalLoginRequest(LoginInput::login(RESET_EMAIL), LoginInput::password(LocalLoginWorld::PASSWORD), new ClientAddress(RESET_IP)))->session;
    $world->request()->request(resetRequest());
    $world->login->policy = SessionWorld::policy(['staff' => ['local_factors' => 'passkey_or_two_factors']]);

    $outcome = $world->reset()->reset(newPassword($world->mailedToken(), previous: $first?->token->credential()));

    expect($outcome)->toEqual(PasswordResetOutcome::changed())
        ->and($world->login->sessions->count())->toBe(0);
});

it('mails no link and sets no password for an actor linked to an authoritative connection (invariant 38)', function (): void {
    $world = new PasswordResetWorld;
    $world->login->policy = SessionWorld::policy(['authoritative_connections' => ['entra'], 'staff' => ['connections' => ['entra' => true]]]);
    $actor = $world->login->person(RESET_EMAIL);
    $world->request()->request(resetRequest());
    $token = $world->mailedToken();
    $world->links->link($actor->id, new IdpIdentity(new ConnectionId('entra'), new Issuer('https://login.example.test'), new Subject('s-1')));

    $answer = $world->request()->request(resetRequest());

    expect($answer)->toEqual($world->request()->request(resetRequest('nobody@example.com')))
        ->and($world->mail->sent())->toHaveCount(1)
        ->and($world->links()->issue(new LoginIdentifier(RESET_EMAIL))->refusal)->toBe(ErrorCode::LoginAuthoritativeLink)
        ->and($world->reset()->reset(newPassword($token)))->toEqual(PasswordResetOutcome::refused(ErrorCode::PasswordResetTokenInvalid))
        ->and($world->login->sessions->count())->toBe(0)
        ->and(passwordIs($world, $actor->id, LocalLoginWorld::PASSWORD))->toBeTrue();
});

it('mails no link and sets no password when the policy has password reset or local login off', function (array $policy, ErrorCode $code): void {
    $world = new PasswordResetWorld;
    $actor = $world->login->person(RESET_EMAIL);
    $world->request()->request(resetRequest());
    $token = $world->mailedToken();
    $world->login->policy = SessionWorld::policy($policy);

    $world->request()->request(resetRequest());

    expect($world->mail->sent())->toHaveCount(1)
        ->and($world->links()->issue(new LoginIdentifier(RESET_EMAIL))->refusal)->toBe($code)
        ->and($world->reset()->reset(newPassword($token)))->toEqual(PasswordResetOutcome::refused(ErrorCode::PasswordResetTokenInvalid))
        ->and(passwordIs($world, $actor->id, LocalLoginWorld::PASSWORD))->toBeTrue();
})->with([
    'password reset off' => [['staff' => ['methods' => ['password_reset' => false]]], ErrorCode::LoginMethodNotAllowed],
    'local login off' => [['staff' => ['local_login' => false]], ErrorCode::LoginLocalDisabled],
]);

it('sets no password for an actor deactivated after the link was issued', function (): void {
    $world = new PasswordResetWorld;
    $actor = $world->login->person(RESET_EMAIL);
    $world->request()->request(resetRequest());
    $token = $world->mailedToken();
    $world->login->identity->changeState($actor->id, ActorState::Deactivated);

    expect($world->reset()->reset(newPassword($token)))->toEqual(PasswordResetOutcome::refused(ErrorCode::PasswordResetTokenInvalid))
        ->and($world->login->sessions->count())->toBe(0)
        ->and(passwordIs($world, $actor->id, LocalLoginWorld::PASSWORD))->toBeTrue();
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

it('refuses a login that checked the old password while a reset set a new one, and leaves it no session', function (): void {
    $world = new PasswordResetWorld;
    $world->login->person(RESET_EMAIL);
    $world->request()->request(resetRequest());
    $token = $world->mailedToken();
    $reset = null;
    $world->login->hasher->whenVerified(static function () use ($world, $token, &$reset): void {
        $reset = $world->reset()->reset(newPassword($token));
    });

    $login = $world->login->action()->login(new LocalLoginRequest(LoginInput::login(RESET_EMAIL), LoginInput::password(LocalLoginWorld::PASSWORD), new ClientAddress(RESET_IP)));
    $owner = $reset instanceof PasswordResetOutcome ? $reset->session : null;

    expect($reset?->refusal)->toBeNull()
        ->and($owner)->not->toBeNull()
        ->and($login->session)->toBeNull()
        ->and($login->refusal)->toBe(ErrorCode::LoginRejected)
        ->and($world->login->sessions->count())->toBe(1)
        ->and($world->login->verifier()->verify($owner?->token->credential()))->toBeInstanceOf(ActorPrincipal::class);
});

it('refuses a login that checked the old password while the password was changed, and leaves it no session', function (): void {
    $world = new PasswordResetWorld;
    $actor = $world->login->person(RESET_EMAIL);
    $world->login->hasher->whenVerified(static function () use ($world, $actor): void {
        $world->login->accounts->changePassword($actor->id, $world->login->hasher->hash(new Password(PasswordResetWorld::NEW_PASSWORD)));
    });

    $login = $world->login->action()->login(new LocalLoginRequest(LoginInput::login(RESET_EMAIL), LoginInput::password(LocalLoginWorld::PASSWORD), new ClientAddress(RESET_IP)));

    expect($login->session)->toBeNull()
        ->and($login->refusal)->toBe(ErrorCode::LoginRejected)
        ->and($world->login->sessions->count())->toBe(0);
});
