<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Actions;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Identity\Login\Actions\LogInLocally;
use Cbox\Cms\Identity\Login\Domain\Dto\LocalLoginRequest;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginOutcome;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginThrottleKeys;
use Cbox\Cms\Identity\Login\Domain\LoginField;
use Cbox\Cms\Identity\Login\Domain\ThrottleScope;
use Cbox\Cms\Identity\Sessions\Domain\Dto\NewSession;
use Cbox\Cms\Identity\Sessions\Domain\SessionKey;
use Cbox\Cms\Identity\Tests\Login\LocalLoginWorld;
use Closure;
use RuntimeException;

/*
 * LogInLocally (PRD 5.16) called directly with its DTO over LocalLoginWorld's fakes (GUARDRAILS 9):
 * a member of staff with the right password gets a new session; an unknown email, a wrong password
 * and an actor that is not active are refused alike with login_rejected; empty fields are
 * validation_required and are not counted; the sixth failed login for one identifier in the window
 * is login_rate_limited before any password is checked; a login that succeeds clears its count and
 * ends the session the browser still carried.
 */

const LOGIN_EMAIL = 'mette.holm@example.com';

const LOGIN_IP = '192.0.2.10';

function localLogin(string $email = LOGIN_EMAIL, string $password = LocalLoginWorld::PASSWORD, ?TransportCredential $previous = null): LocalLoginRequest
{
    return new LocalLoginRequest($email, $password, LOGIN_IP, $previous);
}

function loggedIn(LoginOutcome $outcome): NewSession
{
    expect($outcome->refusal)->toBeNull();

    return $outcome->session ?? throw new RuntimeException('The login issued no session.');
}

it('logs a member of staff in with the right password and issues a new session that verifies as a person', function (): void {
    $world = new LocalLoginWorld;
    $actor = $world->person(LOGIN_EMAIL);

    $session = loggedIn($world->action()->login(localLogin(' Mette.Holm@Example.com ')));
    $principal = $world->verifier()->verify($session->token->credential());

    expect($principal)->toBeInstanceOf(ActorPrincipal::class)
        ->and($principal instanceof ActorPrincipal && $principal->actor->equals($actor->id))->toBeTrue()
        ->and($principal instanceof ActorPrincipal ? $principal->issuerKind : null)->toBe(IssuerKind::Human)
        ->and($world->hasher->verified)->toHaveCount(1)
        ->and($world->telemetry->counted('cms.session.issued'))->toBe(1)
        ->and($world->throttle->count(ThrottleScope::Identifier, LoginThrottleKeys::of(LOGIN_EMAIL, LOGIN_IP)))->toBe(0);
});

it('refuses an unknown email, a wrong password and an actor that is not active alike, with login_rejected', function (Closure $refused): void {
    $world = new LocalLoginWorld;
    $world->person(LOGIN_EMAIL);
    $world->person('pending@example.com', ActorState::Pending);
    $world->person('gone@example.com', ActorState::Deactivated);
    $request = $refused();
    assert($request instanceof LocalLoginRequest);

    $outcome = $world->action()->login($request);

    expect($outcome->session)->toBeNull()
        ->and($outcome->refusal)->toBe(ErrorCode::LoginRejected)
        ->and($outcome->fields)->toBe([])
        ->and($world->hasher->verified)->toHaveCount(1)
        ->and($world->sessions->count())->toBe(0)
        ->and($world->throttle->count(ThrottleScope::Identifier, LoginThrottleKeys::of($request->identifier, LOGIN_IP)))->toBe(1);
})->with([
    'an unknown email' => [fn (): LocalLoginRequest => localLogin('nobody@example.com')],
    'a wrong password' => [fn (): LocalLoginRequest => localLogin(password: 'not the password at all')],
    'a pending actor' => [fn (): LocalLoginRequest => localLogin('pending@example.com')],
    'a deactivated actor' => [fn (): LocalLoginRequest => localLogin('gone@example.com')],
]);

it('refuses empty fields with validation_required on each, without counting an attempt or checking a password', function (): void {
    $world = new LocalLoginWorld;
    $world->person(LOGIN_EMAIL);

    $both = $world->action()->login(localLogin(' ', ''));
    $password = $world->action()->login(localLogin(password: ''));

    expect($both->refusal)->toBe(ErrorCode::ValidationRequired)
        ->and($both->fields)->toBe([LoginField::Identifier, LoginField::Password])
        ->and($password->fields)->toBe([LoginField::Password])
        ->and($world->hasher->verified)->toBe([])
        ->and($world->throttle->count(ThrottleScope::Ip, LoginThrottleKeys::of(LOGIN_EMAIL, LOGIN_IP)))->toBe(0);
});

it('refuses the sixth failed login for one identifier in the window with login_rate_limited, without checking its password', function (): void {
    $world = new LocalLoginWorld;
    $world->person(LOGIN_EMAIL);
    $login = $world->action();

    foreach (range(1, 5) as $attempt) {
        expect($login->login(localLogin(password: 'wrong password '.$attempt))->refusal)->toBe(ErrorCode::LoginRejected);
    }

    $sixth = $login->login(localLogin());
    $counted = array_values(array_filter($world->telemetry->counters(), static fn (CounterRecord $counter): bool => $counter->name->value === LogInLocally::RATE_LIMITED));

    expect($sixth->session)->toBeNull()
        ->and($sixth->refusal)->toBe(ErrorCode::LoginRateLimited)
        ->and($world->hasher->verified)->toHaveCount(5)
        ->and($counted)->toHaveCount(1)
        ->and($counted[0]->attributes->get(LogInLocally::LIMIT))->toBe('identifier')
        ->and($world->sessions->count())->toBe(0);
});

it('clears the identifier\'s count when the login succeeds', function (): void {
    $world = new LocalLoginWorld;
    $world->person(LOGIN_EMAIL);
    $login = $world->action();

    foreach (range(1, 4) as $attempt) {
        $login->login(localLogin(password: 'wrong password '.$attempt));
    }

    loggedIn($login->login(localLogin()));

    expect($world->throttle->count(ThrottleScope::Identifier, LoginThrottleKeys::of(LOGIN_EMAIL, LOGIN_IP)))->toBe(0)
        ->and($world->throttle->count(ThrottleScope::Ip, LoginThrottleKeys::of(LOGIN_EMAIL, LOGIN_IP)))->toBe(4);
});

it('ends the session the browser still carried and ignores a value that is no session id', function (): void {
    $world = new LocalLoginWorld;
    $world->person(LOGIN_EMAIL);
    $login = $world->action();

    $first = loggedIn($login->login(localLogin()));
    $second = loggedIn($login->login(localLogin(previous: $first->token->credential())));
    $third = loggedIn($login->login(localLogin(previous: TransportCredential::session('not-a-session'))));

    expect($world->sessions->find(SessionKey::of($first->token)))->toBeNull()
        ->and($world->sessions->find(SessionKey::of($second->token)))->not->toBeNull()
        ->and($world->sessions->find(SessionKey::of($third->token)))->not->toBeNull()
        ->and($second->token->hash())->not->toBe($first->token->hash());
});
