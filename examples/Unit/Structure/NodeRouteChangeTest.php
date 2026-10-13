<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutations\NodeRouteMoved;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Structure\Domain\Commands\ChangeNodeRoute;

// A news site renames its section from /nyheder to /aktuelt: version 2 of node.set_route moves the
// section's route, and every URL below /nyheder answers 301 to the same path below /aktuelt.

it('moves a node\'s route with version 2 of node.set_route', function (): void {
    $section = NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000921');
    $site = SiteId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000922');

    $change = new ChangeNodeRoute($section, new AggregateVersion(3), $site, new Locale('da'), new RequestPath('/aktuelt'));
    $moved = new NodeRouteMoved($section, $site, new Locale('da'), '/nyheder', '/aktuelt');

    expect($change->expectedVersions()->of($section))->toEqual(ReadVersion::at($section, new AggregateVersion(3)))
        ->and([$moved->from, $moved->to])->toBe(['/nyheder', '/aktuelt'])
        ->and(fn (): NodeRouteMoved => new NodeRouteMoved($section, $site, new Locale('da'), '/aktuelt', '/aktuelt'))->toThrow(InvalidArgumentException::class);
});
