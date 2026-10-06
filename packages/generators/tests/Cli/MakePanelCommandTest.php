<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Cli;

use Cbox\Cms\Generators\Cli\Console\MakePanelCommand;
use Cbox\Cms\Generators\Scaffold\Domain\IndexModule;
use Cbox\Cms\Generators\Tests\Scaffold\ScaffoldWorld;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Illuminate\Contracts\Console\Kernel;

/*
 * cms:make:panel in the testbench application (PRD 13.4, section 7 of the panel extension
 * architecture): for the addon tally of ScaffoldWorld, into a scratch copy of its package. A
 * compiled check needs only its id and goes into the registration and the ids; a fill the
 * manifest does not have yet takes its point and query and gets the manifest line; an action
 * gets the manifest line alone; and a bad kind, id, point or command exits 64 with what was wrong.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

it('scaffolds a compiled check, and then a fill the manifest does not have yet', function (): void {
    $package = ScaffoldWorld::bindToApplication();
    $kernel = app(Kernel::class);

    $check = $kernel->call('cms:make:panel', ['kind' => 'check', 'namespace' => 'tally', 'id' => 'tally.title-check']);
    $checkOutput = $kernel->output();
    $fill = $kernel->call('cms:make:panel', ['kind' => 'fill', 'namespace' => 'tally', 'id' => 'tally.extra', '--point' => 'notes.detail.card@1', '--query' => 'tally.notes@1']);
    $fillOutput = $kernel->output();

    expect($check)->toBe(0)
        ->and($checkOutput)->toContain(
            'written: resources/panel/src/TitleCheckCheck.ts',
            'written: resources/panel/src/TitleCheckCheck.test.ts',
            'written: resources/panel/src/index.ts',
            'written: resources/panel/src/ids.ts',
            'Scaffolded the check tally.title-check.',
        )
        ->and($fill)->toBe(0)
        ->and($fillOutput)->toContain(
            'written: resources/panel/src/Extra.tsx',
            'written: resources/panel/src/Extra.test.tsx',
            "new SlotFill(new ContributionId('tally.extra'), 'notes.detail.card@1', data: <the class of the query tally.notes@1>::class)",
            'Scaffolded the fill tally.extra.',
        )
        ->and((string) file_get_contents($package.'/'.IndexModule::INDEX))->toContain("'tally.extra': () => import('./Extra'),", "'tally.title-check': titleCheckCheck,")
        ->and((string) file_get_contents($package.'/'.IndexModule::IDS))->toContain("'tally.extra',", "'tally.title-check',");
});

it('scaffolds an action as the manifest line alone', function (): void {
    $package = ScaffoldWorld::bindToApplication();
    $kernel = app(Kernel::class);

    $status = $kernel->call('cms:make:panel', ['kind' => 'action', 'namespace' => 'tally', 'id' => 'tally.request-more', '--point' => 'shell.user-menu@1', '--command' => 'entry.create@1']);

    expect($status)->toBe(0)
        ->and($kernel->output())->toContain("new ActionContribution(new ContributionId('tally.request-more'), 'shell.user-menu@1', 'entry.create@1', 'tally.request-more.label')")
        ->and($kernel->output())->not->toContain('written:')
        ->and(is_dir($package.'/resources/panel/src'))->toBeFalse();
});

it('refuses arguments it does not take with exit 64, and writes nothing', function (array $arguments, string $message): void {
    $package = ScaffoldWorld::bindToApplication();
    $kernel = app(Kernel::class);

    expect($kernel->call('cms:make:panel', $arguments))->toBe(64)
        ->and($kernel->output())->toContain($message)
        ->and(is_dir($package.'/resources'))->toBeFalse();
})->with([
    'a kind it does not scaffold' => [['kind' => 'page', 'namespace' => 'tally', 'id' => 'tally.queue'], 'The kind "page" is not one cms:make:panel scaffolds: give fill, action, check or step.'],
    'an id outside the namespace' => [['kind' => 'fill', 'namespace' => 'tally', 'id' => 'approvals.badge'], 'The contribution id approvals.badge is not in the namespace tally'],
    'a point that is no point id' => [['kind' => 'fill', 'namespace' => 'tally', 'id' => 'tally.extra', '--point' => 'notes.detail.card'], '--point takes a point id, <name>@<version>'],
    'a check on a point without its command' => [['kind' => 'check', 'namespace' => 'tally', 'id' => 'tally.extra', '--point' => 'notes.form.checks@1'], 'A check is on a command: give --command=<name>@<version>'],
    'a severity it does not know' => [['kind' => 'check', 'namespace' => 'tally', 'id' => 'tally.extra', '--point' => 'notes.form.checks@1', '--command' => 'entry.create@1', '--severity' => 'loud'], '--severity takes info, warning, acknowledge or error.'],
    'a path that is no field path' => [['kind' => 'step', 'namespace' => 'tally', 'id' => 'tally.extra', '--point' => 'notes.form.steps@1', '--command' => 'entry.create@1', '--patch' => ['fields..reason']], '--patch takes a path of a command document'],
    'a kind the point does not take' => [['kind' => 'fill', 'namespace' => 'tally', 'id' => 'tally.extra', '--point' => 'notes.form.checks@1'], '[generate_panel_contribution_mismatch] The point notes.form.checks@1 is of the kind form_check, which takes no fill.'],
    'a point no package declares' => [['kind' => 'fill', 'namespace' => 'tally', 'id' => 'tally.extra', '--point' => 'notes.missing@1'], '[generate_panel_point_unknown] No installed package declares the panel point notes.missing@1.'],
    'an addon that is not installed' => [['kind' => 'fill', 'namespace' => 'reviews', 'id' => 'reviews.badge', '--point' => 'notes.detail.card@1'], '[generate_panel_addon_unknown] No installed addon has the namespace reviews.'],
]);

it('is registered with the generators', function (): void {
    expect(app(Kernel::class)->all())->toHaveKey('cms:make:panel')
        ->and(app(Kernel::class)->all()['cms:make:panel'])->toBeInstanceOf(MakePanelCommand::class);
});
