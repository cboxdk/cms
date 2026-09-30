<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Console;

use Cbox\Cms\Core\Entries\Actions\CreateEntryAction;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Workbench\FixtureAddon\DeriveSlug;

/*
 * cms:actions against the workbench (GUARDRAILS 7.1): every action of its registry, compiled from
 * its scan roots as cms:build compiles them, with the command or query it handles, its surfaces,
 * the permission a grant needs and the hooks that run for it, among them the fixture addon's
 * transform hook of entry.create; as lines and as one JSON document. A registry cache that
 * cannot be read exits 78 with its code.
 */

/**
 * @return array{int, string}
 */
function actionsCli(bool $json = false): array
{
    $status = Artisan::call('cms:actions', $json ? ['--json' => true] : []);

    return [$status, Artisan::output()];
}

/**
 * @return array<array-key, mixed>
 */
function actionsDocument(string $output): array
{
    $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    return is_array($document) ? $document : throw new RuntimeException('The output is not a JSON object.');
}

it('lists every action of the workbench with its command or query, surfaces, grant and hooks', function (): void {
    $registry = WorkbenchRegistry::bind();

    [$status, $output] = actionsCli();

    expect($status)->toBe(0)
        ->and($output)->toContain(
            "entry.create v1  write  Cbox\\Cms\\Core\\Entries\\Actions\\CreateEntryAction (cboxdk/cms)\n"
            ."  command   Cbox\\Cms\\Core\\Entries\\Domain\\Commands\\CreateEntry\n"
            ."  surfaces  rest, inertia, mcp, cli\n"
            ."  grant     a role whose permissions hold entry.create\n"
            ."  hooks     transform  priority 10  budget 2 ms  Workbench\\FixtureAddon\\DeriveSlug (cboxdk/cms-fixture-addon, addon fixtureaddon reading up to public)\n",
        )
        ->and($output)->toContain(
            "path.resolve v1  query  Cbox\\Cms\\Core\\Routing\\Actions\\ResolvePathAction (cboxdk/cms)\n"
            ."  query     Cbox\\Cms\\Core\\Routing\\Domain\\Queries\\ResolvePath\n"
            ."  surfaces  none, called by the kernel alone\n"
            ."  grant     a role whose permissions hold path.resolve\n"
            ."  hooks     none, a query runs no hooks\n",
        )
        ->and($output)->toEndWith(sprintf("%d actions.\n", count($registry->actions)));
});

it('prints the actions of the workbench as one JSON document in the registry order', function (): void {
    $registry = WorkbenchRegistry::bind();

    [$status, $output] = actionsCli(json: true);
    $document = actionsDocument($output);
    $actions = is_array($document['actions'] ?? null) ? array_values($document['actions']) : [];
    $create = array_values(array_filter($actions, static fn (mixed $action): bool => is_array($action) && ($action['command'] ?? null) === 'entry.create'));

    expect($status)->toBe(0)
        ->and($document['version'] ?? null)->toBe(1)
        ->and(array_map(static fn (mixed $action): mixed => is_array($action) ? $action['command'] ?? null : null, $actions))
        ->toBe(array_map(static fn (ActionEntry $action): string => $action->command->value, $registry->actions))
        ->and($create)->toBe([[
            'class' => CreateEntryAction::class,
            'command' => 'entry.create',
            'command_class' => CreateEntry::class,
            'command_version' => 1,
            'hooks' => [[
                'addon' => 'fixtureaddon',
                'budget_ms' => 2,
                'class' => DeriveSlug::class,
                'package' => 'cboxdk/cms-fixture-addon',
                'phase' => 'transform',
                'priority' => 10,
                'reads' => 'public',
            ]],
            'kind' => 'write',
            'package' => 'cboxdk/cms',
            'permission' => 'entry.create',
            'surfaces' => ['rest', 'inertia', 'mcp', 'cli'],
        ]]);
});

it('exits 78 with registry_cache_missing or registry_cache_malformed when the registry cache cannot be read', function (bool $damaged, string $code): void {
    WorkbenchRegistry::unreadable($damaged);

    [$status, $output] = actionsCli();
    [$jsonStatus, $json] = actionsCli(json: true);

    expect($status)->toBe(78)
        ->and($output)->toStartWith($code.': ')
        ->and($output)->toContain('cms:build')
        ->and($jsonStatus)->toBe(78)
        ->and(actionsDocument($json)['code'] ?? null)->toBe($code);
})->with([
    'missing' => [false, 'registry_cache_missing'],
    'damaged' => [true, 'registry_cache_malformed'],
]);
