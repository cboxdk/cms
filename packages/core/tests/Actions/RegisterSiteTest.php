<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\SiteRegistered;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Structure\Domain\Commands\RegisterSite;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Structure\Domain\SiteHandleRef;
use Cbox\Cms\Core\Tests\Structure\SiteCommandFakes;

/*
 * site.register (PRD 5.8, 5.9, 11.14) through the maintenance pipeline over the fakes of the ports
 * it reads (GUARDRAILS 9): the action reads the site and its handle, both expected absent, and plans
 * the site with its root node and locales as the installation operator; an empty locale list and a
 * locale named twice are validation_failed; a handle or a site id that exists, when it was read or
 * registered meanwhile, is version_conflict.
 */

const REGISTERED_SITE = '01936f5e-8a2b-7c3d-9e4f-000000000601';

const REGISTERED_ROOT = '01936f5e-8a2b-7c3d-9e4f-000000000602';

const REGISTERED_OTHER = '01936f5e-8a2b-7c3d-9e4f-000000000603';

const REGISTERED_SOUTH = '01936f5e-8a2b-7c3d-9e4f-000000000604';

/**
 * @param  list<string>  $locales
 */
function siteRegistration(string $handle = 'north', array $locales = ['da', 'en']): RegisterSite
{
    return new RegisterSite(
        SiteId::fromString(REGISTERED_SITE),
        new SiteHandle($handle),
        NodeId::fromString(REGISTERED_ROOT),
        array_map(static fn (string $tag): Locale => new Locale($tag), $locales),
    );
}

/**
 * @return list<string> each error as "<code> <path>"
 */
function siteErrors(WriteResult $result): array
{
    return array_map(
        static fn (CatalogError $error): string => trim($error->code->value.' '.($error->path instanceof FieldPath ? $error->path->toString() : '')),
        $result->errors,
    );
}

it('registers the site with its root node and locales, reading the site and its handle as absent', function (): void {
    $world = new SiteCommandFakes;

    $result = $world->run(siteRegistration());
    $mutations = $world->committer->pending[0]->plan->mutations();
    $mutation = $mutations[0];

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($world->committer->pending[0]->command->value)->toBe('site.register')
        ->and($world->reads())->toBe(['actor:'.$world->operator->toString().' 1', 'site:'.REGISTERED_SITE.' -', 'site_handle:north -'])
        ->and($mutations)->toHaveCount(1)
        ->and($mutation)->toBeInstanceOf(SiteRegistered::class)
        ->and($mutation instanceof SiteRegistered ? [$mutation->site->toString(), $mutation->handle, $mutation->root->toString(), StoredSite::tags($mutation->locales)] : [])
        ->toBe([REGISTERED_SITE, 'north', REGISTERED_ROOT, ['da', 'en']])
        ->and($world->sites->reads)->toBe(['id '.REGISTERED_SITE, 'named north']);
});

it('validates a dry run without committing', function (): void {
    $world = new SiteCommandFakes;

    $result = $world->run(siteRegistration(), dryRun: true);

    expect($result->outcome())->toBe(Outcome::DryRun)
        ->and($world->committer->pending)->toBe([]);
});

it('refuses an empty locale list and a locale named twice with validation_failed', function (): void {
    $world = new SiteCommandFakes;

    $empty = $world->run(siteRegistration('south', []), 'sites:empty');
    $twice = $world->run(siteRegistration('west', ['da', 'en', 'DA']), 'sites:twice');

    expect($empty->outcome())->toBe(Outcome::Rejected)
        ->and(siteErrors($empty))->toBe(['validation_failed locales'])
        ->and(siteErrors($twice))->toBe(['validation_failed locales[2]'])
        ->and($world->committer->pending)->toBe([]);
});

it('is a version conflict for a handle another site has, and for a site id that exists', function (): void {
    $world = new SiteCommandFakes;
    $world->sites->add(new StoredSite(SiteId::fromString(REGISTERED_OTHER), new SiteHandle('north'), NodeId::fromString(REGISTERED_OTHER), AggregateVersion::first(), [new Locale('da')]));
    $world->sites->add(new StoredSite(SiteId::fromString(REGISTERED_SOUTH), new SiteHandle('south'), NodeId::fromString(REGISTERED_ROOT), AggregateVersion::first(), [new Locale('da')]));

    $taken = $world->run(siteRegistration(), 'sites:taken');
    $exists = $world->run(new RegisterSite(SiteId::fromString(REGISTERED_SOUTH), new SiteHandle('west'), NodeId::fromString(REGISTERED_OTHER), [new Locale('da')]), 'sites:exists');

    expect($taken->outcome())->toBe(Outcome::Rejected)
        ->and(siteErrors($taken))->toBe(['version_conflict'])
        ->and(siteErrors($exists))->toBe(['version_conflict'])
        ->and($world->committer->pending)->toBe([]);
});

it('is a version conflict when the handle was taken after it was read', function (): void {
    $world = new SiteCommandFakes;
    $world->committer->at(new SiteHandleRef(new SiteHandle('north')), AggregateVersion::first());

    $result = $world->run(siteRegistration());

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(siteErrors($result))->toBe(['version_conflict']);
});

it('replays the first receipt for a rerun of the same unit of work', function (): void {
    $world = new SiteCommandFakes;

    $first = $world->run(siteRegistration(), 'sites:north:locales');
    $again = $world->run(siteRegistration(), 'sites:north:locales');

    expect($first->outcome())->toBe(Outcome::Committed)
        ->and($again->outcome())->toBe(Outcome::Committed)
        ->and($again->receipt->changesetId?->toString())->toBe($first->receipt->changesetId?->toString())
        ->and($world->committer->pending)->toHaveCount(1);
});
