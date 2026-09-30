<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\RestRoute;
use Cbox\Cms\Core\Registry\Domain\InvalidRegistryEntry;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Cbox\Cms\Core\Registry\Domain\RestMethod;

/*
 * The routes of the REST surface (GUARDRAILS 2.1, PRD 8.8): one per action exposed on REST, a POST
 * below /v1/commands for a write and a GET below /v1/queries for a read, with the name and version
 * of the command or query in the path.
 */

it('routes a write to a POST and a read to a GET, with the contract version, the name and the version in the path', function (): void {
    $write = new RestRoute(ActionKind::Write, new CommandName('entry.create'), 1);
    $read = new RestRoute(ActionKind::Query, new CommandName('entry.list'), 12);

    expect([$write->method, $write->path])->toBe([RestMethod::Post, '/v1/commands/entry.create/v1'])
        ->and([$read->method, $read->path])->toBe([RestMethod::Get, '/v1/queries/entry.list/v12'])
        ->and(RestMethod::of(ActionKind::Write)->value)->toBe('POST')
        ->and(RestMethod::of(ActionKind::Query)->value)->toBe('GET');
});

it('gives a route only to an action exposed on REST', function (): void {
    $action = static fn (Surface ...$surfaces): ActionEntry => new ActionEntry('App\A', 'acme/a', ActionKind::Write, new CommandName('a.b'), 2, 'App\C', array_values($surfaces));

    expect(RestRoute::of($action(Surface::Rest, Surface::Mcp)))->toEqual(new RestRoute(ActionKind::Write, new CommandName('a.b'), 2))
        ->and(RestRoute::of($action(Surface::Inertia, Surface::Mcp, Surface::Cli)))->toBeNull()
        ->and(RestRoute::of($action()))->toBeNull();
});

it('refuses a route of version 0', function (): void {
    expect(static fn (): RestRoute => new RestRoute(ActionKind::Query, new CommandName('a.b'), 0))
        ->toThrow(InvalidRegistryEntry::class, 'The REST route of "a.b" has version 0. Versions start at 1.');
});

it('compiles one route per action on REST into the registry, in the order of the actions', function (): void {
    $registry = new RegistryCompiler()->compile(RegistryFixtures::validDiscovery());

    expect(array_map(static fn (RestRoute $route): string => $route->method->value.' '.$route->path, $registry->rest))->toBe(['POST /v1/commands/fixture.note.create/v1'])
        ->and(count($registry->actions))->toBe(2);
});
