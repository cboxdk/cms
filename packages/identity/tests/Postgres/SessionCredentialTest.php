<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Postgres;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\RequestEnvelope;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\Login\AuthenticationMethod;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\Login\VerifiedAssertion;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Contracts\Telemetry\SpanRecord;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Core\Pipeline\Actions\RunExposedCommand;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ExposedCall;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Identity\PostgresIdentity;
use Cbox\Cms\Identity\LoginPolicy\Actions\CheckLoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginAttempt;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginMethod;
use Cbox\Cms\Identity\Sessions\Actions\IssueSession;
use Cbox\Cms\Identity\Sessions\Adapter\SessionCredentialVerifier;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use PHPUnit\Framework\Assert;

/*
 * A session is a credential of every surface (PRD 5.16, 6.2), checked against the actor's state
 * and credential generation in Postgres, the M1 directory, at each request. With the container's
 * verifier, which the identity module decorates, and its session store in Valkey: a session of an
 * actor deactivated after the login is rejected with actor_not_active, and one whose generation
 * is below the actor's after a revocation with credential_revoked, both through RunExposedCommand
 * and through the QueryPipeline, and a valid one runs as the issuer kind human.
 */

/**
 * Logs the actor in locally with a password, as the workbench's policy allows, and returns the
 * session id in the session form, as the session cookie carries it.
 */
function postgresSession(Actor $actor): TransportCredential
{
    $assertion = new VerifiedAssertion(
        new ConnectionId('local'),
        new Issuer('http://localhost'),
        new Subject('subject-1'),
        app(Clock::class)->now(),
        [new AuthenticationMethod('pwd')],
    );

    return app(IssueSession::class)->issue(app(CheckLoginPolicy::class)->check(new LoginAttempt($actor->id, LoginMethod::Password, $assertion)))->token->credential();
}

/**
 * A dry run of actor.deactivate through the REST surface with the credential. The actor holds no
 * grant, so a call that gets past its credential is refused by the authorizer.
 */
function exposedCommand(TransportCredential $credential, Actor $target): WriteResult
{
    return app(RunExposedCommand::class)->run(new ExposedCall(
        Surface::Rest,
        $credential,
        new RequestEnvelope(new IdempotencyKey('session-'.bin2hex(random_bytes(8))), dryRun: true),
        app(CommandCodecs::class)->for(new CommandName('actor.deactivate'), 1),
        json_encode(['actor' => $target->id->toString()], JSON_THROW_ON_ERROR),
    ));
}

function sessionRead(TransportCredential $credential): QueryResult
{
    return app(QueryPipeline::class)->run(new QueryCall(new ResolvePath(new Host('nowhere.example'), new Locale('da'), new RequestPath('/')), $credential, Surface::Rest));
}

/**
 * @param  list<CatalogError>  $errors
 * @return list<ErrorCode>
 */
function errorCodes(array $errors): array
{
    return array_map(static fn (CatalogError $error): ErrorCode => $error->code, $errors);
}

it('decorates the bound verifier with the session verifier', function (): void {
    expect(app(CredentialVerifier::class))->toBeInstanceOf(SessionCredentialVerifier::class);
});

it('runs a command and a read with a valid session as the issuer kind human', function (): void {
    $telemetry = new FakeTelemetry;
    app()->instance(Telemetry::class, $telemetry);
    app()->forgetInstance(PipelineTelemetry::class);
    $identity = PostgresIdentity::at(app(Clock::class));
    $person = $identity->addActor(ActorClass::Staff);
    $session = postgresSession($person);

    $principal = app(CredentialVerifier::class)->verify($session);
    $command = exposedCommand($session, $identity->addActor(ActorClass::Staff));
    $read = sessionRead($session);

    $span = array_first(array_filter($telemetry->spans(), static fn (SpanRecord $span): bool => $span->name->value === 'actor.deactivate')) ?? Assert::fail('The command was not run by the pipeline.');

    expect($principal)->toBeInstanceOf(ActorPrincipal::class)
        ->and($principal instanceof ActorPrincipal ? [$principal->actor->equals($person->id), $principal->issuerKind] : null)->toBe([true, IssuerKind::Human])
        ->and(errorCodes($command->errors))->toBe([ErrorCode::Unauthorized])
        ->and($span->attributes->get(PipelineTelemetry::ISSUER))->toBe(EnvelopeIssuer::Human->value)
        ->and($span->attributes->get(PipelineTelemetry::SURFACE))->toBe('rest')
        ->and($read->errors)->toBe([])
        ->and($read->issuer)->toBe(EnvelopeIssuer::Human);
});

it('rejects a session of an actor deactivated after the login with actor_not_active', function (): void {
    $identity = PostgresIdentity::at(app(Clock::class));
    $person = $identity->addActor(ActorClass::Staff);
    $session = postgresSession($person);
    $target = $identity->addActor(ActorClass::Staff);

    $identity->changeState($person->id, ActorState::Deactivated);

    expect(errorCodes(exposedCommand($session, $target)->errors))->toBe([ErrorCode::ActorNotActive]);

    // The refusal ended the session, so a second request does not find it.
    expect(errorCodes(sessionRead($session)->errors))->toBe([ErrorCode::CredentialUnknown]);

    $second = $identity->addActor(ActorClass::Staff);
    $other = postgresSession($second);
    $identity->changeState($second->id, ActorState::Deactivated);

    expect(errorCodes(sessionRead($other)->errors))->toBe([ErrorCode::ActorNotActive]);
});

it('rejects a session whose generation is below the actor\'s with credential_revoked', function (): void {
    $identity = PostgresIdentity::at(app(Clock::class));
    $person = $identity->addActor(ActorClass::Staff);
    $command = postgresSession($person);
    $read = postgresSession($person);
    $target = $identity->addActor(ActorClass::Staff);

    $identity->revokeCredentials($person->id);

    expect(errorCodes(exposedCommand($command, $target)->errors))->toBe([ErrorCode::CredentialRevoked])
        ->and(errorCodes(sessionRead($read)->errors))->toBe([ErrorCode::CredentialRevoked])
        ->and(errorCodes(exposedCommand(postgresSession($person), $target)->errors))->toBe([ErrorCode::Unauthorized]);
});
