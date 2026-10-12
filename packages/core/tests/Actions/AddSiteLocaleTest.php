<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Plans\Mutations\SiteLocaleAdded;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Structure\Domain\Commands\AddSiteLocale;
use Cbox\Cms\Core\Structure\Domain\Dto\SiteLocaleAggregates;
use Cbox\Cms\Core\Tests\Structure\NodeActionWorld;

/*
 * site.add_locale's action in the command pipeline with fakes (GUARDRAILS 9, PRD 5.9, 5.10, 11.14):
 * an add reads the site at its version and plans the locale. It is decided on the site's root node
 * in the locale, which its authorization scope names, validation_failed for a locale the site
 * publishes in already, and version_conflict for a site at another version and for a site that does
 * not exist, which the command read as absent while the caller named a version of it.
 */

it('plans the locale with the site\'s root and reads the site and its root node', function (): void {
    $world = new NodeActionWorld;

    $result = $world->addLocale();
    $pending = $world->committed();
    [$mutation] = $pending->plan->mutations();

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('site.add_locale')
        ->and($mutation)->toBeInstanceOf(SiteLocaleAdded::class)
        ->and($mutation instanceof SiteLocaleAdded ? [$mutation->site->toString(), $mutation->root->toString(), $mutation->locale->value] : [])
        ->toBe([NodeActionWorld::NORTH, NodeActionWorld::ROOT, 'de'])
        ->and(array_slice($world->reads(), -1))->toBe(['site:'.NodeActionWorld::NORTH.' 1'])
        ->and($world->sites->reads)->toBe(['id '.NodeActionWorld::NORTH]);
});

it('is decided on the site\'s root node in the locale, so the authorizer refuses an actor whose roles do not reach it there', function (): void {
    $world = new NodeActionWorld;

    $world->addLocale();
    $scope = $world->committed()->reads;
    $aggregates = new SiteLocaleAggregates(NodeActionWorld::site(NodeActionWorld::NORTH), $world->sites->find(NodeActionWorld::site(NodeActionWorld::NORTH)), new Locale('de'));
    $absent = new SiteLocaleAggregates(NodeActionWorld::site(NodeActionWorld::NOWHERE), null, new Locale('de'));

    expect(array_map(static fn (AuthorizationTarget $target): string => $target->node->toString().' '.($target->locale->value ?? '*'), $aggregates->authorizationScope()->targets))->toBe([NodeActionWorld::ROOT.' de'])
        ->and($absent->authorizationScope()->targets)->toBe([])
        ->and($scope->reads)->toHaveCount(2);
});

it('refuses a locale the site publishes in already, and a site that does not exist as a version conflict', function (): void {
    $world = new NodeActionWorld;

    $missing = $world->addLocale(site: NodeActionWorld::NOWHERE);
    $published = $world->addLocale('en');

    expect(NodeActionWorld::paths($missing))->toBe(['version_conflict -'])
        ->and(NodeActionWorld::paths($published))->toBe(['validation_failed locale'])
        ->and($published->errors[0]->message)->toContain('publishes in en already')
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict for a site at another version than the caller read', function (): void {
    $world = new NodeActionWorld;

    $stale = $world->addLocale(version: 2);
    $world->commitWith(new VersionConflict(new StaleRead(NodeActionWorld::site(NodeActionWorld::NORTH), AggregateVersion::first(), new AggregateVersion(2))));
    $raced = $world->addLocale();

    expect(NodeActionWorld::codes($stale))->toBe(['version_conflict'])
        ->and(NodeActionWorld::codes($raced))->toBe(['version_conflict'])
        ->and($world->committer->pending)->toHaveCount(1);
});

it('validates a dry run without committing', function (): void {
    $world = new NodeActionWorld;

    $result = $world->run(new AddSiteLocale(NodeActionWorld::site(NodeActionWorld::NORTH), AggregateVersion::first(), new Locale('de')), dryRun: true);

    expect($result->outcome())->toBe(Outcome::DryRun)
        ->and($world->committer->pending)->toBe([]);
});
