<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Core\Identity\Adapter\PostgresActorDirectory;
use Cbox\Cms\Core\Identity\Adapter\PostgresCredentialVerifier;
use Cbox\Cms\Core\Tests\Identity\PostgresIdentity;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use DateInterval;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The Postgres verifier as the app role (PRD 5.16, invariant 37): a credential is refused once its
 * actor's generation is counted up and once an actor of its chain is deactivated, including when
 * the change is written straight to the table, and a wrong checksum is refused before any
 * statement runs.
 */

function refusedAs(CredentialVerifier $verifier, TransportCredential $credential): CredentialErrorCode
{
    try {
        $verifier->verify($credential);
    } catch (CredentialRejected $rejected) {
        return $rejected->reason;
    }

    throw new AssertionFailedError('The credential was verified.');
}

it('binds the Postgres directory and verifier by default', function (): void {
    // The identity module decorates the bound verifier with its session verifier (container
    // extend), so the default is read from cbox-cms.contracts, the binding the decorator wraps.
    expect(app(ActorDirectory::class))->toBeInstanceOf(PostgresActorDirectory::class)
        ->and(config('cbox-cms.contracts.'.CredentialVerifier::class))->toBe(PostgresCredentialVerifier::class)
        ->and(app(CredentialVerifier::class))->toBeInstanceOf(CredentialVerifier::class);
});

it('refuses a credential after its actor\'s generation is increased', function (): void {
    $clock = new FakeClock;
    $identity = PostgresIdentity::at($clock);
    $actor = $identity->addActor(ActorClass::Service);
    $credential = $identity->issue(new ServiceCredentialSpec($actor->id, IssuerKind::Service, ClassificationAccess::Internal, $clock->now()->add(new DateInterval('P7D'))));
    $verifier = $identity->verifier();

    expect($verifier->verify($credential))->toBeInstanceOf(ActorPrincipal::class);

    DB::connection('pgsql_owner')->table('actors')->where('id', $actor->id->toString())->update([
        'credential_generation' => 2,
        'version' => 2,
    ]);

    expect(refusedAs($verifier, $credential))->toBe(CredentialErrorCode::Revoked)
        ->and(refusedAs(new PostgresCredentialVerifier(app(DatabaseManager::class), $clock), $credential))->toBe(CredentialErrorCode::Revoked);
});

it('refuses an agent credential when the person it acts for is deactivated', function (): void {
    $clock = new FakeClock;
    $identity = PostgresIdentity::at($clock);
    $agent = $identity->addActor(ActorClass::Service);
    $person = $identity->addActor(ActorClass::Staff);
    $credential = $identity->issue(new ServiceCredentialSpec($agent->id, IssuerKind::Agent, ClassificationAccess::Confidential, $clock->now()->add(new DateInterval('P1D')), [$person->id]));
    $verifier = $identity->verifier();

    $principal = $verifier->verify($credential);

    expect($principal)->toBeInstanceOf(ActorPrincipal::class)
        ->and($principal instanceof ActorPrincipal ? $principal->issuerKind : null)->toBe(IssuerKind::Agent);

    $identity->changeState($person->id, ActorState::Deactivated);

    expect(refusedAs($verifier, $credential))->toBe(CredentialErrorCode::ActorNotActive)
        ->and($identity->directory()->find($agent->id)?->state)->toBe(ActorState::Active);
});

it('refuses a wrong checksum before any statement runs', function (): void {
    $clock = new FakeClock;
    $identity = PostgresIdentity::at($clock);
    $actor = $identity->addActor(ActorClass::Service);
    $token = $identity->issue(new ServiceCredentialSpec($actor->id, IssuerKind::Service, ClassificationAccess::Public, $clock->now()->add(new DateInterval('P1D'))))->reveal();
    $statements = [];
    DB::listen(static function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    expect(refusedAs($identity->verifier(), new TransportCredential(substr($token, 0, -8).'00000000')))->toBe(CredentialErrorCode::Malformed)
        ->and($statements)->toBe([]);

    $identity->verifier()->verify(new TransportCredential($token));

    expect($statements)->toHaveCount(2);
});
