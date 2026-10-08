<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Actions;

use Cbox\Cms\Contracts\Addons\AddonCapabilities;
use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\LoginNotice;
use Cbox\Cms\Contracts\PanelPoints\PanelApiVersion;
use Cbox\Cms\Contracts\PanelPoints\PanelContributions;
use Cbox\Cms\Contracts\PanelPoints\PanelLocale;
use Cbox\Cms\Contracts\PanelPoints\Tone;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Core\Registry\Domain\Dto\DisabledContributions;
use Cbox\Cms\Core\Tests\Pipeline\Tally\AddTally;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakePanelActivation;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Core\Tests\Registry\PanelBuildWorld;
use Cbox\Cms\Panel\Contributions\Domain\ContributionTelemetry;
use Cbox\Cms\Panel\Domain\Dto\LoginNoticeProp;
use Cbox\Cms\Panel\Login\Actions\ResolveLoginNotices;
use Cbox\Cms\Panel\Tests\Contributions\ContributionWorld;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;

/*
 * ResolveLoginNotices (PRD 13.4): the notices the login page shows are the LoginNotice
 * contributions to login.notice@1 of the compiled registry in render order, priority with the
 * lowest first, then namespace, then id, each with its addon, id, message and tone; the message is
 * the text of the addon's compiled catalogue in the page's locale, the key itself when the addon
 * ships none, because a credential page carries no catalogue; a notice the activation state
 * disables is left out; and when the registry cannot be read the page shows none, recorded in
 * telemetry, so nothing an addon does keeps a person from the login form.
 */

const NOTICE_LATER = 'tally.notice-later';

const NOTICE_FIRST = 'tally.notice-first';

/**
 * The test addon's manifest with two notices beside its other contributions, accepting the point.
 */
function noticesManifest(): AddonManifest
{
    return new AddonManifest(
        ContributionWorld::ADDON,
        new AddonNamespace('tally'),
        new CoreApiVersion(CoreApiVersion::CURRENT_MAJOR, CoreApiVersion::CURRENT_MINOR),
        __DIR__.'/../Contributions/Fixtures/Tally',
        new AddonCapabilities(ClassificationAccess::Internal, [AddTally::class]),
        panel: new PanelContributions(PanelApiVersion::current(), PanelBuildWorld::BUNDLE, [...ContributionWorld::POINTS, 'login.notice@1'], [
            ...ContributionWorld::contributions(),
            new LoginNotice(new ContributionId(NOTICE_LATER), 'login.notice@1', 'tally.notice_later.message', Tone::Warning, priority: 20),
            new LoginNotice(new ContributionId(NOTICE_FIRST), 'login.notice@1', 'tally.nav.board', priority: 10),
        ], lang: ContributionWorld::LANG),
    );
}

/**
 * @return list<array{string, string, string, string}>
 */
function listedNotices(ResolveLoginNotices $action, PanelLocale $locale = PanelLocale::FALLBACK): array
{
    return array_map(static fn (LoginNoticeProp $notice): array => [$notice->addon->value, $notice->id->value, $notice->message, $notice->tone->value], $action->resolve($locale)->notices);
}

it('gives the enabled notices of the point in render order, each with its addon, id, message and tone', function (): void {
    $registry = ContributionWorld::registry(noticesManifest());
    $telemetry = new FakeTelemetry;
    $activation = new FakePanelActivation;

    // tally.nav.board is a key of the addon's catalogue, so its text travels; the later notice's
    // key is in no catalogue, so the key does, which the host shows as the key.
    expect(listedNotices(new ResolveLoginNotices(ContributionWorld::cache($registry), $activation, new ContributionTelemetry($telemetry))))->toBe([
        ['tally', NOTICE_FIRST, 'Board', 'neutral'],
        ['tally', NOTICE_LATER, 'tally.notice_later.message', 'warning'],
    ])
        ->and(listedNotices(new ResolveLoginNotices(ContributionWorld::cache($registry), $activation, new ContributionTelemetry($telemetry)), PanelLocale::Danish))->toBe([
            ['tally', NOTICE_FIRST, 'Tavle', 'neutral'],
            ['tally', NOTICE_LATER, 'tally.notice_later.message', 'warning'],
        ])
        ->and($telemetry->counters())->toBe([]);

    $activation->set(new DisabledContributions(contributions: [new ContributionId(NOTICE_FIRST)]));

    expect(listedNotices(new ResolveLoginNotices(ContributionWorld::cache($registry), $activation, new ContributionTelemetry($telemetry))))->toBe([
        ['tally', NOTICE_LATER, 'tally.notice_later.message', 'warning'],
    ]);

    $activation->set(new DisabledContributions(addons: [new AddonNamespace('tally')]));

    expect(listedNotices(new ResolveLoginNotices(ContributionWorld::cache($registry), $activation, new ContributionTelemetry($telemetry))))->toBe([]);
});

it('gives no notice when no addon contributes one', function (): void {
    expect(listedNotices(new ResolveLoginNotices(ContributionWorld::cache(ContributionWorld::registry()), new FakePanelActivation, new ContributionTelemetry(new FakeTelemetry))))->toBe([]);
});

it('gives no notice and records why when the registry cannot be read', function (): void {
    $telemetry = new FakeTelemetry;

    expect(listedNotices(new ResolveLoginNotices(new FakeRegistryCache, new FakePanelActivation, new ContributionTelemetry($telemetry))))->toBe([])
        ->and(array_map(static fn (CounterRecord $counter): string => $counter->name->value, $telemetry->counters()))->toBe([ContributionTelemetry::WITHHELD]);
});
