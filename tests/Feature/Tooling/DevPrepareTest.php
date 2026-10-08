<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Tooling\ComposerScripts;
use Illuminate\Contracts\Console\Kernel;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\StringInput;

/*
 * `composer dev:prepare` readies the workbench and its dev database for cms:doctor and composer
 * workbench:serve (PRD 4.2, 13.2), each step in the dev image as composer image:run runs it, so
 * every step reaches Postgres and Valkey by their service names as the gates and the served
 * workbench do: the workbench's application key in workbench/.env, which the panel needs; the
 * migrations as the owner role; then the partition runway, which cms:partitions:maintain creates
 * on the owner connection and which the doctor's partitions.runway check reads; then the registry
 * cache; then the installation operator, which cms:install creates once on the owner connection and
 * the doctor's identity.operator_actor check reads; then the configured sites, which
 * cms:sites:sync registers as that operator, so a grant has a node to hold on; and last the
 * panel's build. A migration adds the tables that maintenance partitions, and the operator's
 * genesis writes into those partitions, so the order matters. Composer runs the steps one by one
 * and stops at the first that fails, with its exit code. tests/Postgres/GettingStartedPathTest.php
 * runs the workbench's steps twice and holds the second run to changing nothing.
 */

const DEV_IMAGE = '@php tools/bin/dev-image.php -- ';

const TESTBENCH = DEV_IMAGE.'php vendor/bin/testbench ';

/**
 * A step of the script as the testbench command it runs, bound to that command's definition, so
 * an unknown command or option fails here as it would on the command line.
 *
 * @return array{Command, StringInput}
 */
function testbenchStep(string $step): array
{
    if (! str_starts_with($step, TESTBENCH)) {
        throw new RuntimeException("The step [{$step}] does not run vendor/bin/testbench in the dev image.");
    }

    $input = new StringInput(substr($step, strlen(TESTBENCH)));
    $name = $input->getFirstArgument() ?? throw new RuntimeException("The step [{$step}] names no command.");
    $command = app(Kernel::class)->all()[$name] ?? null;

    if (! $command instanceof Command) {
        throw new RuntimeException("The step [{$step}] runs [{$name}], which is not a command.");
    }

    $command->mergeApplicationDefinition();
    $input->bind($command->getDefinition());
    $input->validate();

    return [$command, $input];
}

/**
 * The steps of the script that run vendor/bin/testbench, in order.
 *
 * @return list<string>
 */
function testbenchSteps(): array
{
    return array_values(array_filter(ComposerScripts::steps('dev:prepare'), static fn (string $step): bool => str_starts_with($step, TESTBENCH)));
}

it('writes the application key, migrates, maintains the partitions, builds the registry, installs the operator, syncs the sites and builds the panel, in that order, each in the dev image', function (): void {
    expect(ComposerScripts::steps('dev:prepare'))->toBe([
        'Composer\\Config::disableProcessTimeout',
        DEV_IMAGE.'php tools/bin/workbench-env.php',
        TESTBENCH.'migrate --database=pgsql_owner --ansi',
        TESTBENCH.'cms:partitions:maintain --ansi',
        TESTBENCH.'cms:build --ansi',
        TESTBENCH.'cms:install --ansi',
        TESTBENCH.'cms:sites:sync --ansi',
        ...ComposerScripts::steps('panel:build'),
    ]);

    $names = array_map(static fn (string $step): ?string => testbenchStep($step)[0]->getName(), testbenchSteps());

    expect($names)->toBe(['migrate', 'cms:partitions:maintain', 'cms:build', 'cms:install', 'cms:sites:sync'])
        ->and(is_file(Codebase::root().'/tools/bin/workbench-env.php'))->toBeTrue()
        ->and(array_slice(ComposerScripts::steps('panel:build'), -1)[0] ?? '')->toStartWith(DEV_IMAGE);
});

it('runs the migrations on the owner connection, the owner role and not the app role', function (): void {
    [, $migrate] = testbenchStep(testbenchSteps()[0]);
    $owner = config()->string('cbox-cms.database.owner_connection');
    $app = config()->string('database.default');

    expect($migrate->getOption('database'))->toBe($owner)
        ->and($migrate->getOption('force'))->toBeFalse()
        ->and($migrate->getOption('pretend'))->toBeFalse()
        ->and(config("database.connections.{$owner}.username"))->toBeString();
    expect($owner)->not->toBe($app);
    expect(config("database.connections.{$owner}.username"))->not->toBe(config("database.connections.{$app}.username"));
});

it('maintains every partitioned table ahead of the clock on the owner connection, not a range of them', function (): void {
    [$command, $maintain] = testbenchStep(testbenchSteps()[1]);

    expect($command->getDefinition()->hasOption('database'))->toBeFalse()
        ->and($maintain->getOption('from'))->toBeNull()
        ->and($maintain->getOption('to'))->toBeNull();
});

it('is described, and the agent guides tell to run it after services:up from the main checkout', function (): void {
    expect(ComposerScripts::description('dev:prepare'))
        ->toContain('composer services:up', 'main checkout', 'cbox-cms.database.owner_connection', 'idempotent', 'exits with its code', 'dev image', 'APP_KEY', 'composer panel:build');

    foreach (['CLAUDE.md', 'AGENTS.md'] as $guide) {
        $text = (string) file_get_contents(Codebase::root().'/'.$guide);

        expect($text)->toMatch('/`composer services:up`[^\n]*`composer dev:prepare`[^\n]*main checkout/');
    }
});
