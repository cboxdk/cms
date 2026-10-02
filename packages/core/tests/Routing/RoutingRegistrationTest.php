<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Routing;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Core\Reads\Domain\QueryActions;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Routing\Actions\ResolvePathAction;
use Cbox\Cms\Core\Routing\Adapter\PostgresRouteReader;
use Cbox\Cms\Core\Routing\Domain\Dto\ConfiguredSite;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Routing\Domain\RouteReader;
use Cbox\Cms\Core\Routing\Domain\SiteHosts;

/*
 * path.resolve is wired into the kernel (PRD 5.9, 13.2): cms:build registers its action as the
 * query action of version 1 on no surface, the query pipeline gets it from the container with its
 * reads on Postgres, and the configured sites follow the configuration.
 */

it('registers path.resolve version 1 as a query action on no surface', function (): void {
    $entry = app(CompiledRegistry::class)->actionFor(ResolvePath::class);

    expect([$entry?->class, $entry?->command->value, $entry?->commandVersion, $entry?->kind, $entry?->surfaces])
        ->toBe([ResolvePathAction::class, 'path.resolve', 1, ActionKind::Query, []]);
});

it('gives the query pipeline the action, with its reads on Postgres and the configured sites', function (): void {
    config(['cbox-cms.sites' => ['north' => ['origin' => 'https://north.example', 'locales' => ['da', 'en']]]]);
    $binding = app(QueryActions::class)->for(new ResolvePath(new Host('north.example'), new Locale('da'), new RequestPath('/')));

    expect([$binding->query->value, $binding->version])->toBe(['path.resolve', 1])
        ->and($binding->action)->toBeInstanceOf(ResolvePathAction::class)
        ->and(app(RouteReader::class))->toBeInstanceOf(PostgresRouteReader::class)
        ->and(array_map(static fn (ConfiguredSite $site): string => $site->handle->value, app(SiteHosts::class)->sites))->toBe(['north']);
});
