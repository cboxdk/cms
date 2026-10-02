<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Maintenance\Actions\InstallOperator;
use Cbox\Cms\Core\Maintenance\Domain\Dto\Genesis;
use Cbox\Cms\Core\Maintenance\Domain\InstallRefused;
use Cbox\Cms\Core\Tests\Maintenance\Fakes\FakeInstallationOperator;
use Cbox\Cms\Core\Tests\Maintenance\Fakes\FakeOperatorGenesis;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use DateTimeImmutable;

/*
 * InstallOperator (PRD 5.16, 3.3), the action behind cms:install, called directly with the fakes of
 * its ports (GUARDRAILS 9): the first run writes the genesis at the Clock's time with new ids, a
 * run of an installation with an operator writes nothing and answers with it, and a process
 * without the owner connection is refused.
 */

/**
 * @return array{InstallOperator, FakeInstallationOperator, FakeOperatorGenesis}
 */
function installAction(?ActorId $installed = null, bool $ownerConnection = true): array
{
    $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
    $installation = new FakeInstallationOperator($installed);
    $genesis = new FakeOperatorGenesis($installation, $ownerConnection);

    return [new InstallOperator($installation, $genesis, new FakeIdGenerator(clock: $clock), $clock), $installation, $genesis];
}

it('creates the operator in a genesis at the Clock\'s time with new ids', function (): void {
    [$action, $installation, $genesis] = installAction();

    $installed = $action->install();
    $written = $genesis->written[0] ?? null;

    expect($installed->created)->toBeTrue()
        ->and($written)->toBeInstanceOf(Genesis::class)
        ->and($written?->operator->equals($installed->operator))->toBeTrue()
        ->and($written?->at->format(DATE_ATOM))->toBe('2026-03-10T12:00:00+00:00')
        ->and($written?->changeset->toString())->not->toBe($installed->operator->toString())
        ->and($installation->operator?->equals($installed->operator))->toBeTrue();
});

it('writes nothing and answers with the operator an installation has', function (): void {
    $existing = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000521');
    [$action, , $genesis] = installAction($existing);

    $installed = $action->install();
    $again = $action->install();

    expect($installed->created)->toBeFalse()
        ->and($installed->operator->equals($existing))->toBeTrue()
        ->and($again->operator->equals($existing))->toBeTrue()
        ->and($genesis->written)->toBe([]);
});

it('is refused without the owner connection', function (): void {
    [$action, $installation] = installAction(ownerConnection: false);

    expect(static fn () => $action->install())->toThrow(InstallRefused::class, '[install_owner_connection_required]')
        ->and($installation->operator)->toBeNull();
});
