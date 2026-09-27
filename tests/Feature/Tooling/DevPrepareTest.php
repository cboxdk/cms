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
 * `composer dev:prepare` readies the workbench's dev database for cms:doctor (PRD 4.2, 13.2): the
 * migrations as the owner role, then the partition runway, which cms:partitions:maintain creates
 * on the owner connection and which the doctor's partitions.runway check reads, then the registry
 * cache. A migration adds the tables that maintenance partitions, so the order matters. Composer
 * runs the steps one by one and stops at the first that fails, with its exit code.
 */

const TESTBENCH = '@php vendor/bin/testbench ';

/**
 * A step of the script as the testbench command it runs, bound to that command's definition, so
 * an unknown command or option fails here as it would on the command line.
 *
 * @return array{Command, StringInput}
 */
function testbenchStep(string $step): array
{
    if (! str_starts_with($step, TESTBENCH)) {
        throw new RuntimeException("The step [{$step}] does not run vendor/bin/testbench.");
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

it('migrates, then maintains the partitions, then builds the registry, in that order', function (): void {
    expect(ComposerScripts::steps('dev:prepare'))->toBe([
        TESTBENCH.'migrate --database=pgsql_owner --ansi',
        TESTBENCH.'cms:partitions:maintain --ansi',
        TESTBENCH.'cms:build --ansi',
    ]);

    $names = array_map(static fn (string $step): ?string => testbenchStep($step)[0]->getName(), ComposerScripts::steps('dev:prepare'));

    expect($names)->toBe(['migrate', 'cms:partitions:maintain', 'cms:build']);
});

it('runs the migrations on the owner connection, the owner role and not the app role', function (): void {
    [, $migrate] = testbenchStep(ComposerScripts::steps('dev:prepare')[0]);
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
    [$command, $maintain] = testbenchStep(ComposerScripts::steps('dev:prepare')[1]);

    expect($command->getDefinition()->hasOption('database'))->toBeFalse()
        ->and($maintain->getOption('from'))->toBeNull()
        ->and($maintain->getOption('to'))->toBeNull();
});

it('is described, and the agent guides tell to run it after services:up from the main checkout', function (): void {
    expect(ComposerScripts::description('dev:prepare'))
        ->toContain('composer services:up', 'main checkout', 'cbox-cms.database.owner_connection', 'idempotent', 'exits with its code');

    foreach (['CLAUDE.md', 'AGENTS.md'] as $guide) {
        $text = (string) file_get_contents(Codebase::root().'/'.$guide);

        expect($text)->toMatch('/`composer services:up`[^\n]*`composer dev:prepare`[^\n]*main checkout/');
    }
});
