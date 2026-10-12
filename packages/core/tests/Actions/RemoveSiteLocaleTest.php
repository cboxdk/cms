<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Plans\Mutations\SiteLocaleRemoved;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Structure\Domain\Dto\SiteLocaleAggregates;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Structure\Domain\SiteLocaleUseRef;
use Cbox\Cms\Core\Tests\Structure\NodeActionWorld;

/*
 * site.remove_locale's action in the command pipeline with fakes (GUARDRAILS 9, PRD 5.9, 5.10,
 * 11.14): a removal reads the site at its version and the site's use of the locale, which it
 * expects absent, and plans the locale's retirement. It is decided on the site's root node in the
 * locale, which its authorization scope names, site_locale_in_use while a placement in the locale is not
 * withdrawn, validation_failed for a locale the site does not publish in and the site's last
 * locale, and version_conflict for a site at another version, for a site that does not exist,
 * which the command read as absent while the caller named a version of it, and for a placement
 * created in the locale before the commit.
 */

it('plans the locale\'s retirement and reads the site and its use of the locale as absent', function (): void {
    $world = new NodeActionWorld;

    $result = $world->removeLocale();
    $pending = $world->committed();
    [$mutation] = $pending->plan->mutations();

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('site.remove_locale')
        ->and($mutation)->toBeInstanceOf(SiteLocaleRemoved::class)
        ->and($mutation instanceof SiteLocaleRemoved ? [$mutation->site->toString(), $mutation->root->toString(), $mutation->locale->value] : [])
        ->toBe([NodeActionWorld::NORTH, NodeActionWorld::ROOT, 'en'])
        ->and(array_slice($world->reads(), -2))->toBe([
            'site:'.NodeActionWorld::NORTH.' 1',
            'site_locale_use:'.NodeActionWorld::NORTH.':en -',
        ])
        ->and($world->use->reads)->toBe([NodeActionWorld::NORTH.' en']);
});

it('refuses a locale a placement still uses with site_locale_in_use, with the count and the next step', function (): void {
    $world = new NodeActionWorld;

    $result = $world->removeLocale('da');

    expect(NodeActionWorld::paths($result))->toBe(['site_locale_in_use locale'])
        ->and($result->errors[0]->message)->toBe(sprintf('The site %s still has 1 placement in da that is not withdrawn. Withdraw it, or move the content to another locale, and run the command again.', NodeActionWorld::NORTH))
        ->and($world->committer->pending)->toBe([]);
});

it('is decided on the site\'s root node in the locale, which its authorization scope names', function (): void {
    $world = new NodeActionWorld;
    $aggregates = new SiteLocaleAggregates(NodeActionWorld::site(NodeActionWorld::NORTH), $world->sites->find(NodeActionWorld::site(NodeActionWorld::NORTH)), new Locale('en'));

    expect(array_map(static fn (AuthorizationTarget $target): string => $target->node->toString().' '.($target->locale->value ?? '*'), $aggregates->authorizationScope()->targets))->toBe([NodeActionWorld::ROOT.' en']);
});

it('refuses a locale the site does not publish in and the site\'s last locale, and a site that does not exist as a version conflict', function (): void {
    $world = new NodeActionWorld;
    $world->use->withPlacements(NodeActionWorld::site(NodeActionWorld::NORTH), new Locale('da'), 0);

    $missing = $world->removeLocale(site: NodeActionWorld::NOWHERE);
    $unpublished = $world->removeLocale('de');
    $world->removeLocale('en');
    $world->sites->sites = [];
    $world->sites->add(new StoredSite(NodeActionWorld::site(NodeActionWorld::NORTH), new SiteHandle('north'), $world->root->id, new AggregateVersion(2), [new Locale('da')]));
    $last = $world->removeLocale('da', 2);

    expect(NodeActionWorld::paths($missing))->toBe(['version_conflict -'])
        ->and(NodeActionWorld::paths($unpublished))->toBe(['validation_failed locale'])
        ->and(NodeActionWorld::paths($last))->toBe(['validation_failed locale'])
        ->and($last->errors[0]->message)->toContain('last one')
        ->and($world->committer->pending)->toHaveCount(1);
});

it('is version_conflict for a site at another version, and for a placement created in the locale before the commit', function (): void {
    $world = new NodeActionWorld;

    $stale = $world->removeLocale(version: 2);
    $world->committer->at(new SiteLocaleUseRef(NodeActionWorld::site(NodeActionWorld::NORTH), new Locale('en')), AggregateVersion::first());
    $raced = $world->removeLocale();

    expect(NodeActionWorld::codes($stale))->toBe(['version_conflict'])
        ->and(NodeActionWorld::codes($raced))->toBe(['version_conflict'])
        ->and($raced->errors[0]->message)->toContain('site_locale_use:'.NodeActionWorld::NORTH.':en')
        ->and($world->committer->pending)->toHaveCount(1);
});

it('is version_conflict when the commit finds the site changed', function (): void {
    $world = new NodeActionWorld;
    $world->commitWith(new VersionConflict(new StaleRead(NodeActionWorld::site(NodeActionWorld::NORTH), AggregateVersion::first(), new AggregateVersion(2))));

    expect(NodeActionWorld::codes($world->removeLocale()))->toBe(['version_conflict']);
});
