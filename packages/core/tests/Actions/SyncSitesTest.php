<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Core\Routing\Domain\Dto\ConfiguredSite;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Routing\Domain\SiteOrigin;
use Cbox\Cms\Core\Structure\Actions\SyncSites;
use Cbox\Cms\Core\Structure\Domain\Commands\RegisterSite;
use Cbox\Cms\Core\Structure\Domain\Dto\SitesSync;
use Cbox\Cms\Core\Structure\Domain\Dto\SiteSync;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Tests\Structure\SiteCommandFakes;

/*
 * cms:sites:sync's action, SyncSites (PRD 11.14), called directly with its DTO over the fakes of the
 * ports it reads (GUARDRAILS 9): a configured site the directory lacks is registered through
 * site.register as the installation operator on a maintenance envelope keyed by the handle and its
 * locales; a site registered with the same locales, in any order, is left alone; a site registered
 * with other locales is site_locales_drift and nothing of it is written, while the next site is still
 * registered; a rejection of the pipeline, such as no operator, is reported per site.
 */

const SYNCED_NORTH = '01936f5e-8a2b-7c3d-9e4f-000000000701';

const SYNCED_ROOT = '01936f5e-8a2b-7c3d-9e4f-000000000702';

/**
 * @param  list<string>  $locales
 */
function configuredSite(string $handle, array $locales): ConfiguredSite
{
    return new ConfiguredSite(new SiteHandle($handle), new SiteOrigin("https://{$handle}.example"), array_map(static fn (string $tag): Locale => new Locale($tag), $locales));
}

/**
 * @param  list<string>  $locales
 */
function storedNorth(SiteCommandFakes $world, array $locales): StoredSite
{
    return $world->sites->add(new StoredSite(SiteId::fromString(SYNCED_NORTH), new SiteHandle('north'), NodeId::fromString(SYNCED_ROOT), AggregateVersion::first(), array_map(static fn (string $tag): Locale => new Locale($tag), $locales)));
}

/**
 * @return list<string> each site as "<handle> <outcome> <errors>"
 */
function syncLines(SiteCommandFakes $world, SyncSites $sync, ConfiguredSite ...$sites): array
{
    return array_map(
        static fn (SiteSync $site): string => trim(sprintf('%s %s %s', $site->handle->value, $site->outcome->value, implode(',', array_map(static fn (CatalogError $error): string => $error->code->value, $site->errors)))),
        $sync->sync(new SitesSync(array_values($sites)))->sites,
    );
}

it('registers each configured site the directory lacks through site.register as the operator, keyed by its handle and locales', function (): void {
    $world = new SiteCommandFakes;
    $north = configuredSite('north', ['en', 'da']);

    $report = $world->sync()->sync(new SitesSync([$north, configuredSite('south', ['da'])]));
    [$first, $second] = $world->committer->pending;
    $command = $first->command->value === 'site.register' ? $first : null;

    expect(array_map(static fn (SiteSync $site): string => $site->handle->value.' '.$site->outcome->value, $report->sites))->toBe(['north registered', 'south registered'])
        ->and($report->firstError())->toBeNull()
        ->and($command?->envelope->surface)->toBe(IssuingSurface::Maintenance)
        ->and($command?->envelope->actor->equals($world->operator))->toBeTrue()
        ->and($first->envelope->idempotencyKey->equals(Envelope::deriveKey(IssuingSurface::Maintenance, SyncSites::unitOfWork($north))))->toBeTrue()
        ->and(SyncSites::unitOfWork($north)->value)->toBe('sites:north:'.hash('sha256', 'da,en'))
        ->and(SyncSites::unitOfWork(configuredSite('north', ['da', 'en']))->value)->toBe(SyncSites::unitOfWork($north)->value)
        ->and($second->envelope->idempotencyKey->equals($first->envelope->idempotencyKey))->toBeFalse()
        ->and($report->sites[0]->site?->toString())->toBe($first->input instanceof RegisterSite ? $first->input->site->toString() : null)
        ->and($report->sites[0]->changeset)->not->toBeNull();
});

it('leaves a site registered with the same locales, in any order, alone', function (): void {
    $world = new SiteCommandFakes;
    storedNorth($world, ['da', 'en']);

    $lines = syncLines($world, $world->sync(), configuredSite('north', ['en', 'da']));

    expect($lines)->toBe(['north unchanged'])
        ->and($world->committer->pending)->toBe([]);
});

it('reports a drift of the locales as site_locales_drift, writes nothing of the site, and still registers the next', function (): void {
    $world = new SiteCommandFakes;
    storedNorth($world, ['da', 'en']);

    $report = $world->sync()->sync(new SitesSync([configuredSite('north', ['da', 'de']), configuredSite('south', ['da'])]));

    expect(array_map(static fn (SiteSync $site): string => $site->handle->value.' '.$site->outcome->value, $report->sites))->toBe(['north drifted', 'south registered'])
        ->and($report->firstError()?->code->value)->toBe('site_locales_drift')
        ->and($report->firstError()?->message)->toBe('The site north is registered with the locales da, en, and cbox-cms.sites configures da, de. Nothing of the site was changed. Set its locales in the configuration back to da, en, or wait for the locale commands of a later block.')
        ->and($report->sites[0]->site?->toString())->toBe(SYNCED_NORTH)
        ->and($world->committer->pending)->toHaveCount(1)
        ->and($world->committer->pending[0]->input instanceof RegisterSite ? $world->committer->pending[0]->input->handle->value : null)->toBe('south');
});

it('reports each site the pipeline rejects, such as before cms:install, and writes nothing', function (): void {
    $world = new SiteCommandFakes;

    $lines = syncLines($world, $world->sync(installed: false), configuredSite('north', ['da']), configuredSite('south', ['da']));

    expect($lines)->toBe(['north rejected installation_operator_missing', 'south rejected installation_operator_missing'])
        ->and($world->committer->pending)->toBe([]);
});
