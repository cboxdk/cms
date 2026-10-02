<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Signals;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\Signals\SignalPin;
use Cbox\Cms\Testkit\Provisioning\FakeScimProvisioning;
use Cbox\Cms\Testkit\Signals\FakeBackChannelLogoutReceiver;
use Cbox\Cms\Testkit\Signals\FakeSecurityEventReceiver;
use Cbox\Cms\Testkit\Tests\Signals\Planted\AcceptsNonce;
use Cbox\Cms\Testkit\Tests\Signals\Planted\AdmitsEmailSubjects;
use Cbox\Cms\Testkit\Tests\Signals\Planted\ForgetsLogouts;
use Cbox\Cms\Testkit\Tests\Signals\Planted\LogoutSuite;
use Cbox\Cms\Testkit\Tests\Signals\Planted\PlantedSuite;
use Cbox\Cms\Testkit\Tests\Signals\Planted\ReachesEveryConnection;
use Cbox\Cms\Testkit\Tests\Signals\Planted\ReappliesDeactivation;
use Cbox\Cms\Testkit\Tests\Signals\Planted\ReappliesEvents;
use Cbox\Cms\Testkit\Tests\Signals\Planted\ScimSuite;
use Cbox\Cms\Testkit\Tests\Signals\Planted\SecurityEventSuite;

/*
 * The shared suites BackChannelLogoutContract, SecurityEventReceiverContract and
 * ScimProvisioningContract catch what they are for (PRD 5.16): a receiver that forgets the jti and
 * so applies a replay twice, one that takes a nonce or a subject named by email, and a SCIM
 * provisioning that reaches another connection's resources or deactivates twice for one desired
 * state. Each planted implementation fails exactly the cases that check what it gets wrong, and
 * the fakes fail none.
 */

it('passes every case against the fakes', function (): void {
    expect(PlantedSuite::failing(new LogoutSuite(static fn (Clock $clock, SignalPin ...$pins): FakeBackChannelLogoutReceiver => new FakeBackChannelLogoutReceiver($clock, ...$pins))))->toBe([])
        ->and(PlantedSuite::failing(new SecurityEventSuite(static fn (Clock $clock, SignalPin ...$pins): FakeSecurityEventReceiver => new FakeSecurityEventReceiver($clock, ...$pins))))->toBe([])
        ->and(PlantedSuite::failing(new ScimSuite(static fn (): FakeScimProvisioning => new FakeScimProvisioning)))->toBe([]);
});

it('fails a logout receiver that admits a replayed jti', function (): void {
    expect(PlantedSuite::failing(new LogoutSuite(static fn (Clock $clock, SignalPin ...$pins): ForgetsLogouts => new ForgetsLogouts($clock, ...$pins))))->toBe([
        'a_replayed_jti_is_refused_so_the_logout_has_one_effect',
        'a_jti_is_remembered_by_its_issuer',
    ]);
});

it('fails a logout receiver that admits a token with a nonce', function (): void {
    expect(PlantedSuite::failing(new LogoutSuite(static fn (Clock $clock, SignalPin ...$pins): AcceptsNonce => new AcceptsNonce($clock, ...$pins))))->toBe([
        'a_refused_token_does_not_spend_its_jti',
        'a_token_with_a_nonce_is_refused',
        'the_checks_run_in_order',
    ]);
});

it('fails a security event receiver that applies a replayed jti again', function (): void {
    expect(PlantedSuite::failing(new SecurityEventSuite(static fn (Clock $clock, SignalPin ...$pins): ReappliesEvents => new ReappliesEvents($clock, ...$pins))))->toBe([
        'a_replayed_jti_is_acknowledged_without_an_effect',
    ]);
});

it('fails a security event receiver that finds the subject by email', function (): void {
    expect(PlantedSuite::failing(new SecurityEventSuite(static fn (Clock $clock, SignalPin ...$pins): AdmitsEmailSubjects => new AdmitsEmailSubjects($clock, ...$pins))))->toBe([
        'a_refused_token_does_not_spend_its_jti',
        'a_subject_not_named_by_issuer_and_subject_is_refused',
        'the_checks_run_in_order',
    ]);
});

it('fails a SCIM provisioning that reaches another connection\'s resources', function (): void {
    expect(PlantedSuite::failing(new ScimSuite(static fn (): ReachesEveryConnection => new ReachesEveryConnection)))->toBe([
        'a_call_for_another_connections_resource_is_refused',
    ]);
});

it('fails a SCIM provisioning that commits a repeated deactivation again', function (): void {
    expect(PlantedSuite::failing(new ScimSuite(static fn (): ReappliesDeactivation => new ReappliesDeactivation)))->toBe([
        'a_repeated_deactivation_has_one_effect',
        'only_the_connection_that_deactivated_a_user_reactivates_it',
    ]);
});
