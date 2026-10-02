<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Events\DatumKind;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Access\Domain\Commands\AssignGrant;
use Cbox\Cms\Core\Access\Domain\Commands\RevokeGrant;
use Cbox\Cms\Core\Access\Domain\Events\GrantChanged;
use Cbox\Cms\Core\Access\Domain\Events\GrantChangedV1;

// An editor-in-chief gives a reporter the role "desk" on the news section, in Danish only, with
// grant.assign. The caller makes the grant's id. The kernel checks that the editor-in-chief holds
// grant.assign on the section and every permission of the role there in Danish, or refuses the
// grant with grant_escalation_refused. Later grant.revoke ends it, at the version the caller read.

it('grants a role on a node in some locales, and revokes it at the version read', function (): void {
    $grant = GrantId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000901');

    $assign = new AssignGrant(
        $grant,
        ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000902'),
        RoleId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000903'),
        NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000904'),
        GrantEffect::Allow,
        [new Locale('da')],
    );
    $revoke = new RevokeGrant($grant, new AggregateVersion(1));

    expect($assign->expectedVersions()->reads[0]->existed())->toBeFalse()
        ->and($assign->locales)->toEqual([new Locale('da')])
        ->and($revoke->expectedVersions()->reads[0]->version?->value)->toBe(1);
});

it('tells about a grant given or ended with ids alone', function (): void {
    $grant = GrantId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000901');
    $actor = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000902');

    $event = new GrantChanged($grant, 2, new GrantChangedV1(
        $actor,
        RoleId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000903'),
        NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000904'),
    ));
    $data = $event->payload()->data();

    expect(GrantChanged::type()->name)->toBe('grant.changed')
        ->and($event->aggregate()->id->toString())->toBe($grant->toString())
        ->and($event->aggregate()->version)->toBe(2)
        ->and($data->get('actor')->kind)->toBe(DatumKind::Identifier)
        ->and($data->get('actor')->asIdentifier()->value)->toBe($actor->toString());
});

it('refuses an escalation and a grant that needs step-up as the actor\'s rights, on every surface', function (): void {
    $escalation = ErrorCode::GrantEscalationRefused->entry();
    $stepUp = ErrorCode::StepUpRequired->entry();

    expect($escalation->http)->toBe(HttpStatus::Forbidden)
        ->and($escalation->exit)->toBe(ExitCode::NoPerm)
        ->and($escalation->retryable)->toBeFalse()
        ->and($stepUp->http)->toBe(HttpStatus::Forbidden)
        ->and($stepUp->exit)->toBe(ExitCode::NoPerm);
});
