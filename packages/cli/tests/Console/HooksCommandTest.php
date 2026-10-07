<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Console;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Pipeline\Domain\CommandHooks;
use Cbox\Cms\Core\Pipeline\Domain\Dto\BoundHook;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Workbench\FixtureAddon\DeriveSlug;
use Workbench\FixtureAddon\RequireWellFormedSlug;

/*
 * cms:hooks against the workbench (PRD 13.2): the hook map of a command, each version with its
 * hooks in the order the command pipeline runs them and their budgets, as lines and as one JSON
 * document. The map is the pipeline's: the hooks the pipeline binds for entry.create are those
 * cms:hooks lists, in the same order. A name that is no command's, a query's included, exits 64,
 * and a registry cache that cannot be read 78.
 */

/**
 * @return array{int, string}
 */
function hooksCli(string $command, bool $json = false): array
{
    $status = Artisan::call('cms:hooks', ['name' => $command, ...($json ? ['--json' => true] : [])]);

    return [$status, Artisan::output()];
}

/**
 * @return array<array-key, mixed>
 */
function hooksDocument(string $output): array
{
    $document = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    return is_array($document) ? $document : throw new RuntimeException('The output is not a JSON object.');
}

it('prints the hook map of a command of the workbench in run order with its budgets', function (): void {
    WorkbenchRegistry::bind();

    [$create, $createOutput] = hooksCli('entry.create');
    [$release, $releaseOutput] = hooksCli('variant.release');
    [$publish, $publishOutput] = hooksCli('entry.publish');

    expect([$create, $release, $publish])->toBe([0, 0, 0])
        ->and($createOutput)->toBe(
            "entry.create v1: 2 hooks, in the order they run, budget 4 ms in all\n"
            ."  1. transform  priority 10  budget 2 ms  Workbench\\FixtureAddon\\DeriveSlug (cboxdk/cms-fixture-addon, addon fixtureaddon reading up to public)\n"
            ."  2. validate   priority 20  budget 2 ms  Workbench\\FixtureAddon\\RequireWellFormedSlug (cboxdk/cms-fixture-addon, addon fixtureaddon reading up to public)\n",
        )
        ->and($releaseOutput)->toBe(
            "variant.release v1: 1 hook, in the order they run, budget 2 ms in all\n"
            ."  1. validate   priority 10  budget 2 ms  Workbench\\FixtureAddon\\RequireSlugOnRelease (cboxdk/cms-fixture-addon, addon fixtureaddon reading up to public)\n",
        )
        ->and($publishOutput)->toBe("entry.publish v1: no hooks run.\n");
});

it('prints the hook map as one JSON document with the hooks the command pipeline binds, in its order', function (): void {
    WorkbenchRegistry::bind();

    [$status, $output] = hooksCli('entry.create', json: true);
    $bound = array_map(
        static fn (BoundHook $hook): array => [$hook->hook::class, $hook->package, $hook->phase->value, $hook->priority, $hook->budgetMs],
        app(CommandHooks::class)->for(new CommandName('entry.create'), 1),
    );

    expect($status)->toBe(0)
        ->and(hooksDocument($output))->toBe([
            'command' => 'entry.create',
            'version' => 1,
            'versions' => [[
                'budget_ms' => 4,
                'hooks' => [[
                    'addon' => 'fixtureaddon',
                    'budget_ms' => 2,
                    'class' => DeriveSlug::class,
                    'package' => 'cboxdk/cms-fixture-addon',
                    'phase' => 'transform',
                    'priority' => 10,
                    'reads' => 'public',
                ], [
                    'addon' => 'fixtureaddon',
                    'budget_ms' => 2,
                    'class' => RequireWellFormedSlug::class,
                    'package' => 'cboxdk/cms-fixture-addon',
                    'phase' => 'validate',
                    'priority' => 20,
                    'reads' => 'public',
                ]],
                'version' => 1,
            ]],
        ])
        ->and($bound)->toBe([[DeriveSlug::class, 'cboxdk/cms-fixture-addon', 'transform', 10, 2], [RequireWellFormedSlug::class, 'cboxdk/cms-fixture-addon', 'validate', 20, 2]]);
});

it('exits 64 for a name no command of the workbench has, a query\'s and one that is not a name', function (string $name, string $message): void {
    WorkbenchRegistry::bind();

    [$status, $output] = hooksCli($name);
    [$jsonStatus, $json] = hooksCli($name, json: true);

    expect($status)->toBe(64)
        ->and($output)->toContain($message)
        ->and($jsonStatus)->toBe(64)
        ->and($json)->toBe($output);
})->with([
    'unknown' => ['entry.delete', 'No registered command is named entry.delete. The registered commands are access.bootstrap, actor.activate, actor.deactivate, actor.register, entry.create,'],
    'query' => ['path.resolve', 'path.resolve is a query. Hooks run for commands alone'],
    'not a name' => ['Entry Create', 'Entry Create'],
]);

it('exits 78 with the code of a registry cache that cannot be read', function (bool $damaged, string $code): void {
    WorkbenchRegistry::unreadable($damaged);

    [$status, $output] = hooksCli('entry.create');
    [$jsonStatus, $json] = hooksCli('entry.create', json: true);

    expect($status)->toBe(78)
        ->and($output)->toStartWith($code.': ')
        ->and($jsonStatus)->toBe(78)
        ->and(hooksDocument($json)['code'] ?? null)->toBe($code);
})->with([
    'missing' => [false, 'registry_cache_missing'],
    'damaged' => [true, 'registry_cache_malformed'],
]);
